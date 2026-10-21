<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Automations\AutomationDeliveryProgress;
use App\Domain\Automations\AutomationRunStatus;
use App\Mail\AutomationEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Delivers one automation email, then records whether it actually left.
 *
 * The dispatcher used to mark the action `succeeded` as soon as `Mail::queue()`
 * returned — which only means the message was put on a queue. This job is the
 * delivery: the action is `queued` until the mailer accepts the message and
 * `failed` if it does not.
 *
 * ## What "delivered" can honestly mean
 *
 * A mail server accepts a message and then the process dies before the
 * acceptance is written down. Nobody can say whether the message was sent, so
 * this job does not pretend: the attempt is recorded **before** the message
 * leaves, and a later attempt that finds a previous attempt still in flight
 * records the delivery as *uncertain* and does **not** send it again. The
 * uncertainty, with the number of attempts, is the truthful outcome; a blind
 * resend could put a second copy in somebody's inbox.
 *
 * ## Several recipients
 *
 * The action may have more than one recipient, and each of them is delivered
 * by its own job. The state of the action is therefore *derived* from the
 * progress of every recipient: one delivery succeeding does not mark the
 * action as delivered while another one failed.
 */
class SendAutomationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public int $automationActionRunId,
        public string $recipient,
        public string $mailSubject,
        public string $mailBody,
    ) {}

    public function handle(): void
    {
        $decision = AutomationDeliveryProgress::beginAttempt(
            $this->automationActionRunId,
            $this->recipient,
            $this->attempts(),
        );

        if ($decision === AutomationDeliveryProgress::SENT) {
            // Already delivered by an earlier attempt: idempotent success.
            AutomationRunStatus::recomputeForActionRun($this->automationActionRunId);

            return;
        }

        if ($decision === AutomationDeliveryProgress::UNCERTAIN) {
            // A previous attempt never finished and the provider may have
            // accepted the message. It is not sent again.
            AutomationRunStatus::recomputeForActionRun($this->automationActionRunId);

            return;
        }

        try {
            Mail::to($this->recipient)->send(new AutomationEmail($this->mailSubject, $this->mailBody));

            AutomationDeliveryProgress::recordOutcome(
                $this->automationActionRunId,
                $this->recipient,
                AutomationDeliveryProgress::SENT,
                null,
            );
        } catch (\Throwable $e) {
            // A server that answered refused the message: it was not
            // delivered, and a retry is safe. A transport that failed on the
            // way, with no answer, leaves the delivery unknown.
            $uncertain = $this->isUncertain($e);

            AutomationDeliveryProgress::recordOutcome(
                $this->automationActionRunId,
                $this->recipient,
                $uncertain ? AutomationDeliveryProgress::UNCERTAIN : AutomationDeliveryProgress::FAILED,
                $uncertain ? 'delivery_uncertain' : ((string) ($e->getCode() ?: 'mail_failed')),
            );

            throw $e;
        }
    }

    /**
     * Whether the failure leaves the delivery unknown.
     *
     * A server that answered refused the message: it was not delivered, and a
     * retry is safe. A transport that failed on the way, with no answer, may
     * have handed the message over before the connection broke, and that
     * cannot be told apart from a message that never arrived.
     */
    private function isUncertain(\Throwable $e): bool
    {
        if ($e instanceof HttpTransportException) {
            // The HTTP transport always has the server's answer.
            return false;
        }

        if ($e instanceof TransportException) {
            // An SMTP error carries the server's code; no code means the
            // connection failed before anything was said.
            return $e->getCode() === 0;
        }

        return false;
    }
}

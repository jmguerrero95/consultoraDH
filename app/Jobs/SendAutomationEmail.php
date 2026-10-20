<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Automations\AutomationRunStatus;
use App\Mail\AutomationEmail;
use App\Models\AutomationActionRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers one automation email, then records whether it actually left.
 *
 * The dispatcher used to mark the action `succeeded` as soon as `Mail::queue()`
 * returned — which only means the message was put on a queue. This job is the
 * delivery: the action is `queued` until the mailer accepts the message and
 * `failed` if it does not.
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
        try {
            Mail::to($this->recipient)->send(new AutomationEmail($this->mailSubject, $this->mailBody));

            $this->mark('succeeded', null, ['delivered_to' => $this->recipient]);
        } catch (\Throwable $e) {
            $this->mark('failed', (string) ($e->getCode() ?: 'mail_failed'));

            throw $e;
        } finally {
            AutomationRunStatus::recomputeForActionRun($this->automationActionRunId);
        }
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    private function mark(string $status, ?string $errorCode, ?array $snapshot = null): void
    {
        AutomationActionRun::query()
            ->whereKey($this->automationActionRunId)
            ->update([
                'status' => $status,
                'error_code' => $errorCode,
                'result_snapshot' => $snapshot === null ? null : json_encode($snapshot),
                'finished_at' => now(),
            ]);
    }
}

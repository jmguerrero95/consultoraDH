<?php

declare(strict_types=1);

namespace App\Domain\Automations;

use App\Models\AutomationActionRun;
use Illuminate\Support\Facades\DB;

/**
 * The delivery progress of one automation action, recipient by recipient.
 *
 * ## Why this exists
 *
 * An automation that sends an email to three people used to have **one** row
 * for three deliveries, and every delivery job wrote that row. The last one
 * to finish decided the outcome: a failure followed by a success was recorded
 * as a success, and a success followed by a failure was recorded as a failure
 * that nobody could explain.
 *
 * The progress of each recipient is therefore kept separately, and the state
 * of the action is **derived** from all of them. A delivery that succeeds
 * cannot erase the failure of another one, because the state is never written
 * by a delivery: it is computed from what every delivery recorded.
 *
 * ## The uncertain case
 *
 * A mail server accepts a message and then the process dies before the
 * acceptance is written down. Nobody can say whether the message was sent, so
 * the honest answer is "unknown", and an unknown delivery is **not** resent
 * automatically: sending it again could produce a second copy in the
 * recipient's inbox, and not sending it again could lose a message. The
 * uncertainty is recorded, with the number of attempts, so that a person can
 * decide.
 *
 * The same applies to the Telegram channel: the delivery row is the claim, and
 * a claim whose worker disappeared is an uncertain delivery, not a failure that
 * may be retried blindly.
 */
final class AutomationDeliveryProgress
{
    /** The recipient was never addressed. */
    public const NEW = 'new';

    /** An attempt is in flight. */
    public const SENDING = 'sending';

    /** The provider accepted the message. */
    public const SENT = 'sent';

    /** The provider refused the message, or it never left. */
    public const FAILED = 'failed';

    /** The provider may or may not have accepted the message. */
    public const UNCERTAIN = 'uncertain';

    /**
     * Start an attempt and say what the caller has to do about it.
     *
     * The return value is the decision:
     *
     *  - {@see self::SENDING}: the attempt may go ahead.
     *  - {@see self::SENT}: it already went; there is nothing to do.
     *  - {@see self::UNCERTAIN}: a previous attempt never finished and the
     *    provider may have accepted the message. It is **not** sent again.
     */
    public static function beginAttempt(int $actionRunId, string $recipient, int $attempt): string
    {
        return DB::transaction(function () use ($actionRunId, $recipient, $attempt): string {
            $actionRun = AutomationActionRun::query()
                ->whereKey($actionRunId)
                ->lockForUpdate()
                ->first();

            if ($actionRun === null) {
                return self::SENT;
            }

            $snapshot = $actionRun->result_snapshot ?? [];
            $recipients = $snapshot['recipients'] ?? [];
            $previous = $recipients[$recipient]['status'] ?? self::NEW;

            if ($previous === self::SENT) {
                return self::SENT;
            }

            if ($previous === self::SENDING) {
                // A previous attempt started and never finished. The provider
                // may have accepted the message, so the truth is recorded as
                // unknown and nothing is sent again.
                $recipients[$recipient] = array_merge($recipients[$recipient] ?? [], [
                    'status' => self::UNCERTAIN,
                    'error_code' => 'delivery_uncertain',
                    'updated_at' => now()->toIso8601String(),
                ]);

                self::write($actionRun, $recipients);

                return self::UNCERTAIN;
            }

            if ($previous === self::UNCERTAIN) {
                // Already known to be unknown: sending it again would be the
                // unsafe automatic resend this class exists to prevent.
                return self::UNCERTAIN;
            }

            $recipients[$recipient] = [
                'status' => self::SENDING,
                'attempts' => (int) ($recipients[$recipient]['attempts'] ?? 0) + 1,
                'attempt_number' => $attempt,
                'error_code' => null,
                'updated_at' => now()->toIso8601String(),
            ];

            self::write($actionRun, $recipients);

            return self::SENDING;
        });
    }

    /**
     * Record the outcome of one delivery and derive the state of the action.
     */
    public static function recordOutcome(
        int $actionRunId,
        string $recipient,
        string $status,
        ?string $errorCode = null,
    ): void {
        DB::transaction(function () use ($actionRunId, $recipient, $status, $errorCode): void {
            $actionRun = AutomationActionRun::query()
                ->whereKey($actionRunId)
                ->lockForUpdate()
                ->first();

            if ($actionRun === null) {
                return;
            }

            $snapshot = $actionRun->result_snapshot ?? [];
            $recipients = $snapshot['recipients'] ?? [];

            $recipients[$recipient] = array_merge($recipients[$recipient] ?? [], [
                'status' => $status,
                'error_code' => $errorCode,
                'updated_at' => now()->toIso8601String(),
            ]);

            [$actionStatus, $actionError] = self::derive($recipients);

            self::write($actionRun, $recipients, [
                'status' => $actionStatus,
                'error_code' => $actionError,
                'finished_at' => in_array($actionStatus, ['succeeded', 'failed'], true) ? now() : null,
            ]);
        });

        AutomationRunStatus::recomputeForActionRun($actionRunId);
    }

    /**
     * The state of the action, derived from every recipient.
     *
     * @param  array<string, array<string, mixed>>  $recipients
     * @return array{0: string, 1: string|null}
     */
    public static function derive(array $recipients): array
    {
        if ($recipients === []) {
            return ['queued', null];
        }

        $statuses = array_column($recipients, 'status');

        if (in_array(self::SENDING, $statuses, true)) {
            // Somebody is still sending. The action is neither a success nor
            // a failure yet.
            return ['queued', null];
        }

        if (in_array(self::UNCERTAIN, $statuses, true)) {
            return ['failed', 'delivery_uncertain'];
        }

        if (in_array(self::FAILED, $statuses, true)) {
            foreach ($recipients as $recipient) {
                if (($recipient['status'] ?? null) === self::FAILED) {
                    return ['failed', $recipient['error_code'] ?? 'delivery_failed'];
                }
            }

            return ['failed', 'delivery_failed'];
        }

        foreach ($statuses as $status) {
            if ($status !== self::SENT) {
                return ['queued', null];
            }
        }

        return ['succeeded', null];
    }

    /**
     * @param  array<string, array<string, mixed>>  $recipients
     * @param  array<string, mixed>  $extra
     */
    private static function write(AutomationActionRun $actionRun, array $recipients, array $extra = []): void
    {
        $snapshot = $actionRun->result_snapshot ?? [];
        $snapshot['recipients'] = $recipients;

        AutomationActionRun::query()
            ->whereKey($actionRun->id)
            ->update(array_merge([
                'result_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], $extra));
    }
}

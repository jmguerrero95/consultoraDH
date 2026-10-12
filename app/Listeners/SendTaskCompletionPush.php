<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Users\UserStatus;
use App\Events\AiTaskCompleted;
use App\Models\AiTask;
use App\Models\PushNotificationDelivery;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tells the person who asked for the work that it is done.
 *
 * ## What is checked before anything is sent
 *
 *  1. **The task still exists and succeeded.** Events can outlive the row that
 *     caused them, and a notification about work that was rolled back is worse
 *     than none.
 *  2. **The recipient is the owner.** The task's own `user_id`, never a value
 *     carried in the event - so a forged or stale event cannot aim somebody
 *     else's work at somebody else's phone.
 *  3. **The person is still active.** A deactivated account stops being told
 *     about things.
 *  4. **It has not been told already**, by a unique key written before the send.
 *
 * ## What the notification is allowed to contain
 *
 * ## Nothing about the document.
 *
 * A push payload is rendered on a lock screen by the operating system, often on
 * a device somebody else can see. "cartera-enero.xlsx" or any figure from it is
 * a disclosure to whoever is holding the phone, so the message says work is
 * ready and carries only ids - and the deep link it opens is authorised by the
 * API on arrival, not by anything the payload asserts.
 *
 * ## Suggestions are not notified
 *
 * Only background work is. A suggestion is offered on the screen the person is
 * already looking at; interrupting a phone with it would be the opposite of
 * what it is for.
 */
final class SendTaskCompletionPush
{
    public function __construct(
        private readonly WebPushService $push,
    ) {}

    public function handle(AiTaskCompleted $event): void
    {
        $task = AiTask::query()->find($event->taskId);

        if ($task === null || $task->status !== AiTask::STATUS_SUCCEEDED) {
            return;
        }

        $recipient = User::query()->find($task->user_id);

        // Compared against the enum, not the string. `User::$status` is cast to
        // `UserStatus`, so `!== 'active'` is true for *every* user - including
        // active ones - and this check silently disabled the whole feature
        // while looking correct.
        if ($recipient === null || $recipient->status !== UserStatus::Active) {
            return;
        }

        // Written before the send on purpose. A crash between the two loses a
        // notification, which is the cheaper mistake; the alternative is waking
        // somebody up twice about the same finished job.
        $claimed = $this->claimDelivery($task, $recipient);

        if (! $claimed) {
            return;
        }

        try {
            $this->push->sendNotification(
                $recipient,
                'Tu trabajo terminó',
                $this->bodyFor($task),
                [
                    'type' => 'ai_task_completed',
                    'task_id' => $task->id,
                    // Relative, and authorised on arrival: the receiving API
                    // re-checks that this person owns the task, so a payload
                    // cannot be edited to open somebody else's work.
                    'url' => '/asistente?task='.$task->id,
                ],
            );
        } catch (\Throwable $e) {
            // The claim is released so a later attempt can try again. A failure
            // to *send* is not a delivery, and keeping the receipt would turn a
            // transient outage into a permanent silence.
            PushNotificationDelivery::query()
                ->where('user_id', $recipient->id)
                ->where('dedupe_key', $this->dedupeKey($task))
                ->delete();

            Log::warning('Task completion push failed.', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A sentence that says what happened and nothing more.
     */
    private function bodyFor(AiTask $task): string
    {
        return match ($task->kind) {
            AiTask::KIND_DOCUMENT_INGEST => 'El documento ya está listo para consultar.',
            AiTask::KIND_DOCUMENT_GENERATE => 'El documento generado está listo.',
            AiTask::KIND_RESEARCH => 'La investigación ya está lista.',
            default => 'Ya puedes revisar el resultado.',
        };
    }

    /**
     * Reserve the right to notify, atomically.
     *
     * `insertOrIgnore`, not a create wrapped in a try/catch. On PostgreSQL a
     * unique violation aborts the whole transaction, not just the statement, so
     * catching the exception does not recover: every later query in the same
     * transaction fails with "current transaction is aborted". Catching the
     * duplicate turns a harmless double-notification attempt into an error
     * somewhere unrelated.
     *
     * `ON CONFLICT DO NOTHING` reports the outcome through its return value and
     * leaves the transaction usable. The unique index is still the arbiter.
     */
    private function claimDelivery(AiTask $task, User $recipient): bool
    {
        $inserted = DB::table('push_notification_deliveries')->insertOrIgnore([
            'user_id' => $recipient->id,
            'notification_type' => 'ai_task_completed',
            'dedupe_key' => $this->dedupeKey($task),
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted === 1;
    }

    private function dedupeKey(AiTask $task): string
    {
        return 'ai_task_completed:'.$task->id;
    }
}

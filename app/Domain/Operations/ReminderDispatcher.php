<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Users\UserStatus;
use App\Models\OperationalTask;
use App\Models\User;
use App\Notifications\TaskReminderNotification;
use App\Services\WebPushService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The task reminder engine.
 *
 * A reminder is not "a notification row exists". It is a sequence with three
 * distinct moments, and each one is recorded so the next run can tell them
 * apart:
 *
 *   1. **Initial** — `reminder_at` has arrived and nothing was sent yet.
 *      Records `reminder_sent_at`.
 *   2. **Technical retry** — sent, but the device never acknowledged receipt.
 *      Retried with a five-minute interval (`reminder_sent_at` is moved to the
 *      last attempt, `reminder_attempts` counts them).
 *   3. **Second reminder** — the device acknowledged receipt but the person
 *      did not dismiss the task. Sent once, ten minutes after the
 *      acknowledgement, recorded in `reminder_second_sent_at`.
 *
 * A dismissal (`reminder_dismissed_at`) stops all three. A completed or
 * cancelled task stops all three. No path marks reception on the service's
 * acceptance of the push: `reminder_received_at` is written only by the device
 * acknowledgement endpoint.
 */
final class ReminderDispatcher
{
    public const RETRY_AFTER_MINUTES = 5;

    public const SECOND_AFTER_MINUTES = 10;

    public function __construct(private readonly WebPushService $push) {}

    /**
     * @return int Number of reminders sent in this run.
     */
    public function dispatchDue(): int
    {
        $now = now();
        $retryBefore = $now->copy()->subMinutes(self::RETRY_AFTER_MINUTES);
        $secondBefore = $now->copy()->subMinutes(self::SECOND_AFTER_MINUTES);
        $dispatched = 0;

        $candidates = OperationalTask::query()
            ->where('status', TaskStatus::Pending->value)
            ->whereNotNull('reminder_at')
            ->where('reminder_at', '<=', $now)
            ->whereNull('reminder_dismissed_at')
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->whereRaw(
                '(reminder_sent_at IS NULL'
                .' OR (reminder_received_at IS NULL AND reminder_sent_at <= ?)'
                .' OR (reminder_received_at IS NOT NULL AND reminder_second_sent_at IS NULL AND reminder_received_at <= ?))',
                [$retryBefore, $secondBefore],
            )
            ->orderBy('id')
            // A cursor, not `get()`: the candidate set is unbounded and a large
            // backlog should be streamed instead of hydrated whole.
            ->cursor();

        foreach ($candidates as $candidate) {
            $claim = $this->claim($candidate->id, $now, $retryBefore, $secondBefore);

            if ($claim === null) {
                continue;
            }

            // The push is a network call, possibly to several endpoints. It runs
            // *after* the transaction commits so the row lock is not held for the
            // duration of somebody else's HTTP timeout.
            try {
                $task = OperationalTask::query()->find($claim['id']);

                if ($task === null) {
                    $this->revertClaim($claim);

                    continue;
                }

                // A dismissal or completion may have arrived between the commit
                // and this line. It wins: the reminder is put back, not sent.
                if ($task->status !== TaskStatus::Pending
                    || $task->completed_at !== null
                    || $task->cancelled_at !== null
                    || $task->reminder_dismissed_at !== null) {
                    $this->revertClaim($claim);

                    continue;
                }

                $this->send($task, $claim['stage']);
            } catch (\Throwable $e) {
                // The claim is compensated so the next run retries this stage
                // instead of recording a push that never left.
                $this->revertClaim($claim);

                Log::error('Task reminder could not be sent; claim reverted', [
                    'task_id' => $claim['id'],
                    'stage' => $claim['stage'],
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Reserve one reminder stage on a task, under the row lock.
     *
     * The transaction only re-reads the row, decides which stage is due and
     * writes the claim. It performs no I/O, so a slow push cannot block another
     * worker, and the unique "who sends this stage" decision is still serialised
     * by the lock.
     *
     * @return array{id: int, stage: string, previous: array<string, mixed>, claimed_at: Carbon}|null
     */
    private function claim(int $id, Carbon $now, Carbon $retryBefore, Carbon $secondBefore): ?array
    {
        return DB::transaction(function () use ($id, $now, $retryBefore, $secondBefore): ?array {
            // The row is re-read under a lock: the candidate list was built
            // before the transaction, and two workers (or a notifier and a
            // dismissal) can change the same task in between.
            $task = OperationalTask::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($task === null
                || $task->status !== TaskStatus::Pending
                || $task->completed_at !== null
                || $task->cancelled_at !== null
                || $task->reminder_dismissed_at !== null) {
                return null;
            }

            $previous = [
                'reminder_sent_at' => $task->reminder_sent_at,
                'reminder_attempts' => $task->reminder_attempts,
                'reminder_second_sent_at' => $task->reminder_second_sent_at,
            ];

            if ($task->reminder_sent_at === null) {
                $task->forceFill([
                    'reminder_sent_at' => $now,
                    'reminder_attempts' => 0,
                ])->save();

                return ['id' => $task->id, 'stage' => 'initial', 'previous' => $previous, 'claimed_at' => $now];
            }

            if ($task->reminder_received_at === null) {
                if ($task->reminder_sent_at->gt($retryBefore)) {
                    return null;
                }

                $task->forceFill([
                    'reminder_sent_at' => $now,
                    'reminder_attempts' => ($task->reminder_attempts ?? 0) + 1,
                ])->save();

                return ['id' => $task->id, 'stage' => 'retry', 'previous' => $previous, 'claimed_at' => $now];
            }

            if ($task->reminder_second_sent_at === null
                && $task->reminder_received_at->lte($secondBefore)) {
                $task->forceFill([
                    'reminder_second_sent_at' => $now,
                ])->save();

                return ['id' => $task->id, 'stage' => 'second', 'previous' => $previous, 'claimed_at' => $now];
            }

            return null;
        });
    }

    /**
     * Put the claim back after a failed or superseded send.
     *
     * The update is conditional on this run still owning the claim, so a
     * concurrent acknowledgement is never overwritten by a stale compensation.
     *
     * @param  array{id: int, stage: string, previous: array<string, mixed>, claimed_at: Carbon}  $claim
     */
    private function revertClaim(array $claim): void
    {
        DB::transaction(function () use ($claim): void {
            $task = OperationalTask::query()
                ->whereKey($claim['id'])
                ->lockForUpdate()
                ->first();

            if ($task === null) {
                return;
            }

            $column = $claim['stage'] === 'second' ? 'reminder_second_sent_at' : 'reminder_sent_at';
            $current = $task->{$column};

            // Somebody else has moved the stage since this claim: leave it.
            if ($current === null || ! $current->equalTo($claim['claimed_at'])) {
                return;
            }

            $task->forceFill([
                'reminder_sent_at' => $claim['previous']['reminder_sent_at'],
                'reminder_attempts' => $claim['previous']['reminder_attempts'],
                'reminder_second_sent_at' => $claim['previous']['reminder_second_sent_at'],
            ])->save();
        });
    }

    /**
     * Write the in-app notification and the Push message for one stage.
     */
    private function send(OperationalTask $task, string $stage): void
    {
        $assignee = $task->assignee;

        if (! $assignee instanceof User || $assignee->status !== UserStatus::Active) {
            Log::info('Task reminder skipped: no active assignee', [
                'task_id' => $task->id,
                'stage' => $stage,
            ]);

            return;
        }

        // The in-app inbox row. This is what the bell reads.
        $assignee->notify(new TaskReminderNotification($task));

        // The Web Push message. Its payload carries the signed acknowledgement
        // and dismissal URLs; the service worker calls them, so reception and
        // dismissal are stated by the device rather than inferred here.
        $this->push->sendNotification(
            $assignee,
            match ($stage) {
                'second' => 'Sigue pendiente: recordatorio de tarea',
                'retry' => 'Recordatorio de tarea (reintento)',
                default => 'Recordatorio de tarea',
            },
            $task->title,
            [
                'type' => 'task_reminder',
                'task_id' => $task->id,
                'url' => '/operacion/tareas?task='.$task->id,
            ],
        );

        Log::info('Task reminder sent', [
            'task_id' => $task->id,
            'stage' => $stage,
            'attempt' => $task->reminder_attempts,
        ]);
    }
}

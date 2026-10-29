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

    /**
     * How long a reservation is considered owned by a live worker.
     *
     * After this, a reservation without an in-app notification is treated as
     * "the worker never sent anything" and can be claimed again. With one, the
     * stage is settled: the user-visible reminder exists, and the Push outcome
     * is unknown rather than retried — which is the only way to keep the second
     * notice unique.
     */
    public const DELIVERY_LEASE_MINUTES = 5;

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

            $task = OperationalTask::query()->find($claim['id']);

            // A dismissal or completion may have arrived between the claim and
            // this line. It wins: nothing is sent and the reservation is released.
            if ($task === null
                || $task->status !== TaskStatus::Pending
                || $task->completed_at !== null
                || $task->cancelled_at !== null
                || $task->reminder_dismissed_at !== null) {
                $this->clearDelivery($claim['token']);

                continue;
            }

            $assignee = $task->assignee;

            if (! $assignee instanceof User || $assignee->status !== UserStatus::Active) {
                // Preserved behaviour: a task whose assignee is inactive still
                // settles its stage, so it is not retried forever.
                Log::info('Task reminder skipped: no active assignee', [
                    'task_id' => $task->id,
                    'stage' => $claim['stage'],
                ]);

                $this->finalize($claim, $task, $now);

                continue;
            }

            // The in-app row and the "internal reminder written" marker are one
            // unit. Both are local database writes, so a transaction is cheap and
            // it removes the only way this stage could be retried after the
            // notification already existed: if the marker cannot be written, the
            // notification is rolled back with it and the next run writes both
            // again. No duplicate reaches the person.
            try {
                DB::transaction(function () use ($assignee, $task, $claim, $now): void {
                    $assignee->notify(new TaskReminderNotification($task));
                    $this->markInternal($claim['token'], $now);
                });
            } catch (ReminderDeliveryLost $e) {
                // Another worker owns the reservation now. This worker's
                // transaction rolled the notification back, and it must not
                // touch (clear or update) the reservation that is no longer its
                // own. No Push follows this branch.
                Log::warning('Task reminder reservation lost before the internal marker; nothing sent', [
                    'task_id' => $task->id,
                    'stage' => $claim['stage'],
                ]);

                continue;
            } catch (\Throwable $e) {
                $this->clearDelivery($claim['token']);

                Log::error('Task reminder notification could not be written; delivery released', [
                    'task_id' => $task->id,
                    'stage' => $claim['stage'],
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            // The Push call is network I/O and stays outside every transaction.
            try {
                $this->sendPush($task, $claim['stage']);
            } catch (\Throwable $e) {
                // The internal reminder is already durable. The external outcome
                // is now unknown: it is not retried here, and the reservation is
                // settled by the lease instead of pretending the push never left.
                Log::error('Task reminder push outcome unknown; internal reminder kept', [
                    'task_id' => $task->id,
                    'stage' => $claim['stage'],
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $this->finalize($claim, $task, $now);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Reserve one reminder stage on a task, under the row lock.
     *
     * The transaction only re-reads the row, settles an abandoned reservation
     * and writes the new one. It performs no I/O, so a slow push cannot block
     * another worker, and "who sends this stage" is still serialised by the lock.
     *
     * @return array{id: int, stage: string, token: string}|null
     */
    private function claim(int $id, Carbon $now, Carbon $retryBefore, Carbon $secondBefore): ?array
    {
        $token = bin2hex(random_bytes(16));

        return DB::transaction(function () use ($id, $token, $now, $retryBefore, $secondBefore): ?array {
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

            if ($this->settleAbandonedDelivery($task, $now)) {
                return null;
            }

            // Another worker's reservation is still inside its lease.
            if ($task->reminder_delivery_token !== null) {
                return null;
            }

            if ($task->reminder_sent_at === null) {
                $stage = 'initial';
            } elseif ($task->reminder_received_at === null) {
                if ($task->reminder_sent_at->gt($retryBefore)) {
                    return null;
                }

                $stage = 'retry';
            } elseif ($task->reminder_second_sent_at === null
                && $task->reminder_received_at->lte($secondBefore)) {
                $stage = 'second';
            } else {
                return null;
            }

            $task->forceFill([
                'reminder_delivery_stage' => $stage,
                'reminder_delivery_token' => $token,
                'reminder_delivery_started_at' => $now,
                'reminder_delivery_internal_at' => null,
            ])->save();

            return ['id' => $task->id, 'stage' => $stage, 'token' => $token];
        });
    }

    /**
     * Resolve a reservation left behind by a worker that stopped mid-delivery.
     *
     * Returns true when the stage was settled here and must not be claimed again
     * in this run.
     */
    private function settleAbandonedDelivery(OperationalTask $task, Carbon $now): bool
    {
        if ($task->reminder_delivery_token === null) {
            return false;
        }

        // The owner is still inside its lease.
        if ($task->reminder_delivery_started_at !== null
            && $task->reminder_delivery_started_at->gt($now->copy()->subMinutes(self::DELIVERY_LEASE_MINUTES))) {
            return true;
        }

        $stage = $task->reminder_delivery_stage;

        if ($task->reminder_delivery_internal_at !== null && $stage !== null) {
            // The user-visible reminder was written. The Push may or may not
            // have left; settling the stage is what keeps the second notice
            // unique and the retry cadence honest.
            $this->writeMarker($task, $stage, $task->reminder_delivery_internal_at);

            Log::warning('Task reminder abandoned after internal delivery; external outcome unknown', [
                'task_id' => $task->id,
                'stage' => $stage,
            ]);

            $task->forceFill($this->emptyDelivery())->save();

            return true;
        }

        // Nothing was delivered: the reservation can be claimed again.
        $task->forceFill($this->emptyDelivery())->save();

        return false;
    }

    private function markInternal(string $token, Carbon $at): void
    {
        $updated = DB::table('operational_tasks')
            ->where('reminder_delivery_token', $token)
            ->update(['reminder_delivery_internal_at' => $at]);

        // Exactly the live reservation, or nothing at all. A token mismatch
        // means this worker paused long enough for the lease to expire and for
        // another worker to take the stage: writing the marker anyway would let
        // this worker continue to Push a reminder somebody else is already
        // delivering, and the second notice would stop being unique.
        if ($updated !== 1) {
            throw ReminderDeliveryLost::claimChanged();
        }
    }

    private function clearDelivery(string $token): void
    {
        DB::table('operational_tasks')
            ->where('reminder_delivery_token', $token)
            ->update($this->emptyDelivery());
    }

    /**
     * @param  array{id: int, stage: string, token: string}  $claim
     */
    private function finalize(array $claim, OperationalTask $task, Carbon $at): void
    {
        DB::transaction(function () use ($claim, $task, $at): void {
            $fresh = OperationalTask::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null
                || $fresh->reminder_delivery_token !== $claim['token']) {
                return;
            }

            $this->writeMarker($fresh, $claim['stage'], $at);
            $fresh->forceFill($this->emptyDelivery())->save();
        });
    }

    /**
     * Write the durable "this stage happened" marker.
     *
     * `reminder_sent_at` is the moment the attempt started, not the moment the
     * process happened to finish: the five-minute retry interval is measured
     * from it.
     */
    private function writeMarker(OperationalTask $task, string $stage, Carbon $at): void
    {
        if ($stage === 'second') {
            $task->forceFill([
                'reminder_second_sent_at' => $task->reminder_second_sent_at ?? $at,
            ])->save();

            return;
        }

        $task->forceFill([
            'reminder_sent_at' => $at,
            'reminder_attempts' => $stage === 'initial' ? 0 : (($task->reminder_attempts ?? 0) + 1),
        ])->save();
    }

    /**
     * @return array<string, null>
     */
    private function emptyDelivery(): array
    {
        return [
            'reminder_delivery_stage' => null,
            'reminder_delivery_token' => null,
            'reminder_delivery_started_at' => null,
            'reminder_delivery_internal_at' => null,
        ];
    }

    /**
     * The Web Push message for one stage.
     */
    private function sendPush(OperationalTask $task, string $stage): void
    {
        $assignee = $task->assignee;

        if (! $assignee instanceof User) {
            return;
        }

        // Its payload carries the signed acknowledgement and dismissal URLs; the
        // service worker calls them, so reception and dismissal are stated by the
        // device rather than inferred here.
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

<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\OperationalTask;
use App\Notifications\TaskReminderNotification;
use Illuminate\Support\Facades\DB;

final class ReminderDispatcher
{
    /**
     * Dispatch due reminders idempotently.
     *
     * A transaction with a row lock claims each task, so two overlapping scheduler
     * ticks cannot send the same reminder twice.
     *
     * @return int The number of reminders dispatched.
     */
    public function dispatchDue(): int
    {
        $now = now();
        $dispatched = 0;

        $dueTasks = OperationalTask::query()
            ->where('status', 'pending')
            ->whereNotNull('reminder_at')
            ->where('reminder_at', '<=', $now)
            ->whereNull('reminder_sent_at')
            ->lockForUpdate()
            ->get();

        foreach ($dueTasks as $task) {
            DB::transaction(function () use ($task, &$dispatched): void {
                $fresh = OperationalTask::query()
                    ->where('id', $task->id)
                    ->lockForUpdate()
                    ->first();

                if ($fresh === null || $fresh->reminder_sent_at !== null) {
                    return;
                }

                $fresh->assignee->notify(new TaskReminderNotification($fresh));

                $fresh->forceFill(['reminder_sent_at' => now()])->save();

                $dispatched++;
            });
        }

        return $dispatched;
    }
}

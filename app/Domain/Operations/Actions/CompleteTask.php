<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Operations\OperationNotApplicable;
use App\Domain\Operations\TaskStatus;
use App\Models\OperationalTask;
use App\Models\User;

final class CompleteTask
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(OperationalTask $task, User $actor): OperationalTask
    {
        if ($task->status === TaskStatus::Done) {
            throw OperationNotApplicable::alreadyDone();
        }

        if ($task->status === TaskStatus::Cancelled) {
            throw OperationNotApplicable::wrongState($task->status->value, TaskStatus::Done->value);
        }

        $task->forceFill([
            'status' => TaskStatus::Done->value,
            'completed_at' => now(),
            'completed_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::TaskCompleted, $actor, [], null, $task);

        return $task;
    }
}

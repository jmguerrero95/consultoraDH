<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Operations\OperationNotApplicable;
use App\Domain\Operations\TaskStatus;
use App\Models\OperationalTask;
use App\Models\User;

final class ReassignTask
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(OperationalTask $task, User $newAssignee, User $actor): OperationalTask
    {
        if ($task->status->isTerminal()) {
            throw OperationNotApplicable::wrongState($task->status->value, TaskStatus::Pending->value);
        }

        $oldAssigneeId = $task->assigned_to;

        $task->forceFill([
            'assigned_to' => $newAssignee->id,
        ])->save();

        $this->audit->record(AuditAction::TaskReassigned, $actor, [
            'from' => $oldAssigneeId,
            'to' => $newAssignee->id,
        ], null, $task);

        return $task;
    }
}

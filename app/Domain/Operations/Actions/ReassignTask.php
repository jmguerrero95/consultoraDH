<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Operations\LockedTask;
use App\Domain\Operations\OperationNotApplicable;
use App\Domain\Operations\TaskAssignee;
use App\Models\OperationalTask;
use App\Models\User;

final class ReassignTask
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(OperationalTask $task, User $newAssignee, User $actor): OperationalTask
    {
        // Checked before the lock so a bad assignee is refused without taking one: the
        // answer does not depend on the task's state.
        TaskAssignee::assertAssignable($newAssignee);

        return LockedTask::of(
            (int) $task->id,
            function (OperationalTask $authoritative) use ($newAssignee, $actor): OperationalTask {
                if ($authoritative->status->isTerminal()) {
                    throw OperationNotApplicable::wrongState(
                        $authoritative->status->value,
                        'in_progress',
                    );
                }

                $from = $authoritative->assigned_to;

                $authoritative->forceFill(['assigned_to' => $newAssignee->id])->save();

                $this->audit->record(AuditAction::TaskReassigned, $actor, [
                    'from' => $from,
                    'to' => $newAssignee->id,
                ], null, $authoritative);

                return $authoritative;
            },
        );
    }
}

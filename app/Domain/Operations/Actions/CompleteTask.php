<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Operations\LockedTask;
use App\Domain\Operations\OperationNotApplicable;
use App\Domain\Operations\TaskStatus;
use App\Models\OperationalTask;
use App\Models\User;

final class CompleteTask
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * The status is decided against the locked row, not against the model the router
     * bound: a task completed by somebody else a moment earlier would otherwise be
     * completed again, and both completions would be audited.
     */
    public function handle(OperationalTask $task, User $actor): OperationalTask
    {
        return LockedTask::of(
            (int) $task->id,
            function (OperationalTask $authoritative) use ($actor): OperationalTask {
                if ($authoritative->status === TaskStatus::Done) {
                    throw OperationNotApplicable::alreadyDone();
                }

                if ($authoritative->status === TaskStatus::Cancelled) {
                    throw OperationNotApplicable::wrongState(
                        $authoritative->status->value,
                        TaskStatus::Done->value,
                    );
                }

                $authoritative->forceFill([
                    'status' => TaskStatus::Done->value,
                    'completed_at' => now(),
                    'completed_by' => $actor->id,
                ])->save();

                $this->audit->record(AuditAction::TaskCompleted, $actor, [], null, $authoritative);

                return $authoritative;
            },
        );
    }
}

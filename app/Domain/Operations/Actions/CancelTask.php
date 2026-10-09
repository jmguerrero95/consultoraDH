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

final class CancelTask
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(OperationalTask $task, User $actor): OperationalTask
    {
        return LockedTask::of(
            (int) $task->id,
            function (OperationalTask $authoritative) use ($actor): OperationalTask {
                // A task that somebody else just completed cannot be cancelled from a
                // copy that still says `pending`: the completion would be erased.
                if ($authoritative->status->isTerminal()) {
                    throw OperationNotApplicable::wrongState(
                        $authoritative->status->value,
                        TaskStatus::Cancelled->value,
                    );
                }

                $authoritative->forceFill([
                    'status' => TaskStatus::Cancelled->value,
                    'cancelled_at' => now(),
                ])->save();

                $this->audit->record(AuditAction::TaskCancelled, $actor, [], null, $authoritative);

                return $authoritative;
            },
        );
    }
}

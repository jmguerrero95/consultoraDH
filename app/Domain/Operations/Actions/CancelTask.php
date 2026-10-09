<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Operations\OperationNotApplicable;
use App\Domain\Operations\TaskStatus;
use App\Models\OperationalTask;
use App\Models\User;

final class CancelTask
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(OperationalTask $task, User $actor): OperationalTask
    {
        if ($task->status->isTerminal()) {
            throw OperationNotApplicable::wrongState($task->status->value, TaskStatus::Cancelled->value);
        }

        $task->forceFill([
            'status' => TaskStatus::Cancelled->value,
            'cancelled_at' => now(),
        ])->save();

        $this->audit->record(AuditAction::TaskCancelled, $actor, [], null, $task);

        return $task;
    }
}

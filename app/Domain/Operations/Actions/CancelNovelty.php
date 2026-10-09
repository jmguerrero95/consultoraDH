<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Operations\NoveltyStatus;
use App\Domain\Operations\OperationNotApplicable;
use App\Models\ClientNovelty;
use App\Models\User;

final class CancelNovelty
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ClientNovelty $novelty, string $reason, User $actor): ClientNovelty
    {
        if ($novelty->status->isTerminal()) {
            throw OperationNotApplicable::wrongState($novelty->status->value, NoveltyStatus::Cancelled->value);
        }

        if (trim($reason) === '') {
            throw OperationNotApplicable::missingReason();
        }

        $novelty->forceFill([
            'status' => NoveltyStatus::Cancelled->value,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
        ])->save();

        $this->audit->record(AuditAction::NoveltyCancelled, $actor, ['reason' => trim($reason)], null, $novelty);

        return $novelty;
    }
}

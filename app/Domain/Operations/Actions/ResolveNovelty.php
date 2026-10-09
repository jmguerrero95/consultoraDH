<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Operations\NoveltyStatus;
use App\Domain\Operations\OperationNotApplicable;
use App\Models\ClientNovelty;
use App\Models\User;

final class ResolveNovelty
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ClientNovelty $novelty, User $actor): ClientNovelty
    {
        if ($novelty->status->isTerminal()) {
            throw OperationNotApplicable::wrongState($novelty->status->value, NoveltyStatus::Resolved->value);
        }

        $novelty->forceFill([
            'status' => NoveltyStatus::Resolved->value,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
        ])->save();

        $this->audit->record(AuditAction::NoveltyResolved, $actor, [], null, $novelty);

        return $novelty;
    }
}

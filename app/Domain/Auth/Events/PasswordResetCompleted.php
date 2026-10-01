<?php

declare(strict_types=1);

namespace App\Domain\Auth\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Models\User;

/**
 * A password was set through the recovery flow.
 */
final readonly class PasswordResetCompleted implements AuditableEvent
{
    public function __construct(
        public User $user,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::PasswordResetCompleted;
    }

    public function auditActor(): User
    {
        return $this->user;
    }

    public function auditMetadata(): array
    {
        return [];
    }
}

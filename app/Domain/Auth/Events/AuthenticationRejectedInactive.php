<?php

declare(strict_types=1);

namespace App\Domain\Auth\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Models\User;

/**
 * The credentials were correct but the account is not active.
 *
 * Recorded separately from a failed login because it is an administrative
 * decision rather than a typo, and it must never be reported to the user with
 * a different message than a wrong password.
 */
final readonly class AuthenticationRejectedInactive implements AuditableEvent
{
    public function __construct(
        public User $user,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::LoginRejectedInactive;
    }

    public function auditActor(): User
    {
        return $this->user;
    }

    public function auditMetadata(): array
    {
        return ['status' => $this->user->status->value];
    }
}

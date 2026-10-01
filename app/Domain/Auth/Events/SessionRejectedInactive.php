<?php

declare(strict_types=1);

namespace App\Domain\Auth\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Models\User;

/**
 * An authenticated session was refused because the account had been made
 * inactive while the session was open.
 *
 * The account actor because it is the account whose access was cut.
 */
final readonly class SessionRejectedInactive implements AuditableEvent
{
    public function __construct(
        public User $user,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::SessionRejectedInactive;
    }

    public function auditActor(): User
    {
        return $this->user;
    }

    /**
     * @return array<string, string>
     */
    public function auditMetadata(): array
    {
        return [
            'email' => $this->user->email,
            'status' => $this->user->status->value,
        ];
    }
}

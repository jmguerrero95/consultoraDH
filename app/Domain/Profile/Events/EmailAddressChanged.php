<?php

declare(strict_types=1);

namespace App\Domain\Profile\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Models\User;

/**
 * The sign in address of the account was changed.
 *
 * Both the previous and the new address are recorded because an administrator
 * investigating an account takeover needs to see which mailbox now owns it.
 * The change required the current password, so the trail is trustworthy.
 */
final readonly class EmailAddressChanged implements AuditableEvent
{
    public function __construct(
        public User $user,
        public string $previousEmail,
        public string $newEmail,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::EmailAddressChanged;
    }

    public function auditActor(): User
    {
        return $this->user;
    }

    public function auditMetadata(): array
    {
        return [
            'previous_email' => $this->previousEmail,
            'new_email' => $this->newEmail,
        ];
    }
}

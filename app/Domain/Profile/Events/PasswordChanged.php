<?php

declare(strict_types=1);

namespace App\Domain\Profile\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Models\User;

/**
 * The password of the account was changed.
 *
 * No value derived from the password is ever recorded, not even a hash.
 */
final readonly class PasswordChanged implements AuditableEvent
{
    /**
     * @param  bool  $selfService  True when the user changed their own password.
     */
    public function __construct(
        public User $user,
        public bool $selfService = true,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::PasswordChanged;
    }

    public function auditActor(): User
    {
        return $this->user;
    }

    public function auditMetadata(): array
    {
        return ['self_service' => $this->selfService];
    }
}

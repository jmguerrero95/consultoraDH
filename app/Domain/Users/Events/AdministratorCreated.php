<?php

declare(strict_types=1);

namespace App\Domain\Users\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Models\User;

/**
 * An administrator account was created from the console.
 *
 * `actor` is null when the command runs on a fresh installation, before any
 * administrator exists.
 */
final readonly class AdministratorCreated implements AuditableEvent
{
    public function __construct(
        public User $user,
        public ?User $actor = null,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::AdministratorCreated;
    }

    public function auditActor(): User
    {
        // A command run from the console has no authenticated operator. Rather
        // than leaving the entry unattributed, it is attributed to the account
        // that was just created, which is what an administrator needs in order
        // to investigate the account afterwards.
        return $this->actor ?? $this->user;
    }

    public function auditMetadata(): array
    {
        return [
            'email' => $this->user->email,
            'roles' => $this->user->getRoleNames()->all(),
        ];
    }
}

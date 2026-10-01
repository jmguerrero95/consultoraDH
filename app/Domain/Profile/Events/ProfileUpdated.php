<?php

declare(strict_types=1);

namespace App\Domain\Profile\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Models\User;

/**
 * The display name of the account was changed.
 *
 * The changed field names are recorded, never the submitted values, so that
 * personal data does not end up duplicated in the audit trail.
 */
final readonly class ProfileUpdated implements AuditableEvent
{
    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public User $user,
        public array $changed,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::ProfileUpdated;
    }

    public function auditActor(): User
    {
        return $this->user;
    }

    public function auditMetadata(): array
    {
        return ['changed' => $this->changed];
    }
}

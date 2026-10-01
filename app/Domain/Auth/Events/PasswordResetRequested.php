<?php

declare(strict_types=1);

namespace App\Domain\Auth\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A password recovery link was requested.
 *
 * The address is recorded because it is the only useful signal when an
 * administrator investigates abuse of the recovery flow. The user is null when
 * the address is unknown, which keeps the endpoint free of user enumeration.
 */
final readonly class PasswordResetRequested implements AuditableEvent
{
    public function __construct(
        public string $email,
        public ?Authenticatable $user = null,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::PasswordResetRequested;
    }

    public function auditActor(): ?Authenticatable
    {
        return $this->user;
    }

    public function auditMetadata(): array
    {
        return [
            'email' => $this->email,
            'account_exists' => $this->user !== null,
        ];
    }
}

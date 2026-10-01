<?php

declare(strict_types=1);

namespace App\Domain\Auth\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Credentials were rejected.
 *
 * The user is populated only when the address exists, which allows an
 * administrator to tell "wrong password for a real account" apart from
 * "attempt against an address that does not exist" when reviewing the trail.
 */
final readonly class AuthenticationFailed implements AuditableEvent
{
    /**
     * @param  string  $email  Already normalised to lower case.
     */
    public function __construct(
        public string $email,
        public ?User $user = null,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::LoginFailed;
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

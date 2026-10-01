<?php

declare(strict_types=1);

namespace App\Domain\Users;

/**
 * Lifecycle state of a user account.
 *
 * The values are the single source of truth for the domain and are mirrored by
 * a CHECK constraint on `users.status`, so an invalid value cannot reach the
 * database through any write path.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * Human readable label, used by the administrative interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Inactive => 'Inactivo',
        };
    }

    /**
     * A user may only sign in while the account is active.
     */
    public function allowsLogin(): bool
    {
        return $this === self::Active;
    }
}

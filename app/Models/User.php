<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Users\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * An account that can sign in to Consultora DH.
 *
 * Authentication is session based. The browser only ever receives an HttpOnly
 * session cookie, never a bearer token; see docs/SECURITY.md.
 */
#[Fillable(['name', 'email', 'password', 'status', 'email_verified_at', 'account_type', 'client_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * Normalise the address on the way in.
     *
     * Combined with the unique index on `users.email` this guarantees that
     * "Admin@Example.com" and "admin@example.com" can never both exist.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null
                ? null
                : mb_strtolower(trim($value)),
        );
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Initials for the interface avatar, derived from the display name.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];

        $letters = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $letters .= mb_substr($part, 0, 1);

            if (mb_strlen($letters) === 2) {
                break;
            }
        }

        return mb_strtoupper($letters === '' ? '?' : $letters);
    }

    /**
     * The highest ranked role, used for the "current role" label.
     */
    public function primaryRoleName(): ?string
    {
        return $this->getRoleNames()->first();
    }

    /**
     * @return HasMany<AuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }
}

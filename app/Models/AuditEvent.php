<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An entry in the append-only audit trail.
 *
 * Rows are never updated: `created_at` is the only timestamp and the model
 * rejects updates and deletes at the ORM level.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'action', 'ip_address', 'user_agent', 'metadata', 'created_at'])]
class AuditEvent extends Model
{
    /**
     * The audit trail is written once and never modified.
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * An audit entry is immutable: refuse updates and deletes.
     */
    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}

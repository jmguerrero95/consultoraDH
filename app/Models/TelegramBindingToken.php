<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use, expiring proof that a chat belongs to an employee.
 *
 * The plaintext token is never stored: only its SHA-256. It is returned once,
 * to the authenticated employee who asked for it, and consumed by the bot when
 * `/start <token>` arrives. That is what stops an arbitrary chat from claiming
 * an account by typing somebody's id, which is what the previous webhook did.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
class TelegramBindingToken extends Model
{
    protected $table = 'telegram_binding_tokens';

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this token may still be exchanged for a binding.
     *
     * A used token and an expired token are both refusals; the reason is kept
     * apart only so the message can be accurate.
     */
    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}

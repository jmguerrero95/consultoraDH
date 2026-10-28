<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One claimed Telegram update.
 *
 * The row is the webhook's idempotency key. The unique index on `update_id`
 * decides which delivery is processed; the loser refuses to run anything.
 *
 * @property int $id
 * @property int $update_id
 * @property string|null $chat_id
 * @property Carbon|null $processed_at
 */
class TelegramUpdate extends Model
{
    /** How long a claim is remembered. Longer than Telegram's retry window. */
    public const RETENTION_DAYS = 7;

    protected $table = 'telegram_updates';

    protected $fillable = [
        'update_id',
        'chat_id',
        'processed_at',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'processed_at' => 'datetime',
            'state' => 'string',
        ];
    }

    public function markProcessed(): void
    {
        $this->forceFill(['processed_at' => now()])->save();
    }
}

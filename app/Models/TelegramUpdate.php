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
 * @property string $state
 * @property string|null $claim_token
 * @property Carbon|null $claimed_at
 * @property Carbon|null $external_started_at
 * @property int $attempt_count
 * @property string|null $failure_code
 * @property Carbon|null $processed_at
 */
class TelegramUpdate extends Model
{
    /** How long a claim is remembered. Longer than Telegram's retry window. */
    public const RETENTION_DAYS = 7;

    public const STATE_PENDING = 'pending';

    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_FAILED_RECOVERABLE = 'failed_recoverable';

    public const STATE_FAILED_EXTERNAL = 'failed_external';

    protected $table = 'telegram_updates';

    protected $fillable = [
        'update_id',
        'chat_id',
        'processed_at',
        'state',
        'claim_token',
        'claimed_at',
        'external_started_at',
        'attempt_count',
        'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'processed_at' => 'datetime',
            'state' => 'string',
            'claimed_at' => 'datetime',
            'external_started_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }

    public function markProcessed(): void
    {
        $this->forceFill(['processed_at' => now()])->save();
    }
}

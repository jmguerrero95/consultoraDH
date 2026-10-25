<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A task the bot proposed in a chat and has not created yet.
 *
 * The proposal is the write's argument record. Confirming sends back only an
 * id, and the task that is created is the one that was proposed, once:
 * `pending` is claimed with a row lock and moved to `confirmed` in the same
 * transaction that inserts the task. A second tap cannot perform the work
 * twice, and an expired proposal cannot be confirmed at all.
 *
 * @property int $id
 * @property int $user_id
 * @property string $chat_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $confirmed_at
 * @property int|null $operational_task_id
 */
class TelegramTaskProposal extends Model
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    protected $table = 'telegram_task_proposals';

    protected $fillable = [
        'user_id',
        'chat_id',
        'title',
        'description',
        'status',
        'expires_at',
        'confirmed_at',
        'operational_task_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(OperationalTask::class, 'operational_task_id');
    }
}

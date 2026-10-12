<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Receipt that one notification was already sent to one person, once.
 *
 * Append-only in practice: a row is written before the send and removed only
 * when the send itself failed, which is what lets a later attempt try again
 * without having turned a transient outage into permanent silence.
 *
 * @property int $id
 * @property int $user_id
 * @property string $notification_type
 * @property string $dedupe_key
 * @property Carbon|null $sent_at
 */
class PushNotificationDelivery extends Model
{
    protected $table = 'push_notification_deliveries';

    protected $fillable = [
        'user_id',
        'notification_type',
        'dedupe_key',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

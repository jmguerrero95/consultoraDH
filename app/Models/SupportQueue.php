<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\LocksRow;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportQueue extends Model
{
    use HasFactory, LocksRow;

    protected $table = 'support_queues';

    protected $fillable = [
        'name',
        'slug',
        'active',
        'is_default',
        'fallback_user_id',
        'escalation_user_id',
        'first_response_minutes',
        'next_response_minutes',
        'resolution_minutes',
        'warning_minutes_before',
        'fallback_email_delay_minutes',
        'telegram_escalation_enabled',
        'created_by',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_default' => 'boolean',
        'first_response_minutes' => 'integer',
        'next_response_minutes' => 'integer',
        'resolution_minutes' => 'integer',
        'warning_minutes_before' => 'integer',
        'fallback_email_delay_minutes' => 'integer',
        'telegram_escalation_enabled' => 'boolean',
    ];

    public function fallbackUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fallback_user_id');
    }

    public function escalationUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalation_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(SupportQueueMember::class, 'queue_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(SupportConversation::class, 'queue_id');
    }

    public function isDefault(): bool
    {
        return $this->is_default;
    }
}

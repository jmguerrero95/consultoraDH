<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramEndpoint extends Model
{
    use HasFactory;

    protected $table = 'telegram_endpoints';

    protected $fillable = [
        'label',
        'chat_id',
        'conversation_id',
        'enabled',
        'event_preferences',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'event_preferences' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The conversation this endpoint is scoped to, or null when it is admin-wide.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    /**
     * Whether this endpoint is allowed to receive the given alert.
     *
     * An endpoint scoped to one conversation only ever answers for that
     * conversation; an admin-wide endpoint (no conversation) answers for any.
     */
    public function receivesFor(?int $conversationId): bool
    {
        return $this->conversation_id === null || $this->conversation_id === $conversationId;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

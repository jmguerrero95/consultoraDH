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
        // The employee the chat belongs to. Null for an admin-wide delivery
        // endpoint that no person can query through; a chat that carries a
        // question must be bound to exactly one active employee.
        'user_id',
        // The assistant thread this chat continues. Deliberately separate from
        // `conversation_id`, which is a support conversation.
        'ai_conversation_id',
        'context',
        'enabled',
        'event_preferences',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'event_preferences' => 'array',
        'context' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The employee this chat speaks for, when it is a private binding.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The conversation this endpoint is scoped to, or null when it is admin-wide.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    /**
     * The assistant thread this private chat continues.
     *
     * Never the support `conversation` above: an alert thread and an AI thread
     * are different resources with different owners and different lifetimes.
     */
    public function aiConversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    /**
     * The small deterministic memory of the chat (last client, last document).
     *
     * @return array<string, mixed>
     */
    public function assistantContext(): array
    {
        $context = $this->context;

        return is_array($context) ? $context : [];
    }

    /**
     * Forget the assistant thread and the deterministic context.
     *
     * Called when the chat changes owner, is rebound, is disabled or its
     * `chat_id` is changed. Keeping the previous context would let a new owner
     * continue somebody else's conversation.
     */
    public function forgetAssistantContext(): void
    {
        $this->forceFill([
            'ai_conversation_id' => null,
            'context' => null,
        ])->save();
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

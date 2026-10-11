<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One assistant conversation.
 *
 * Owned by exactly one user. There is no shared conversation and no
 * administrator-wide conversation: `user_id` is the authorization boundary, and
 * every read and write of a conversation is scoped to it.
 *
 * The optional `client_id` is set by the person choosing to ask about that
 * client. It is never inferred from what the conversation says, because a model
 * choosing its own subject would let a question about one client silently become a
 * question about another.
 */
class AiConversation extends Model
{
    protected $table = 'ai_conversations';

    protected $fillable = [
        'user_id',
        'client_id',
        'title',
    ];

    protected function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'ai_conversation_id')->orderBy('id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AiActionProposal::class, 'ai_conversation_id');
    }
}

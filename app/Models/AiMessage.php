<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of an assistant conversation.
 *
 * `role` is `user`, `assistant` or `tool`. A tool message carries the registry's
 * structured result and is data the model reads, never instructions it follows:
 * business text can contain "ignore previous instructions", and storing it as
 * content is what keeps it a quotation.
 *
 * `provider` and `model` record which model answered. They are identifiers; an
 * API key is a credential and is never stored on a row.
 */
class AiMessage extends Model
{
    protected $table = 'ai_messages';

    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'provider',
        'model',
    ];

    protected function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}

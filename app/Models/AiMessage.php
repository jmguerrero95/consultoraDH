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
    /**
     * §10 turn states. A `completed` turn is history; a `failed` turn is kept so
     * the person still sees their question but is never replayed to the model.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'ai_messages';

    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'provider',
        'model',
        'client_turn_id',
        'status',
        'error',
    ];

    protected function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    /** Whether this turn belongs in the history sent to the model. */
    public function isCompleted(): bool
    {
        return ($this->status ?? self::STATUS_COMPLETED) === self::STATUS_COMPLETED;
    }
}

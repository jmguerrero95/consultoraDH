<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A write the assistant proposed and has not yet performed.
 *
 * ## Why the arguments are stored here
 *
 * The assistant may not mutate anything merely because a model emitted a tool
 * call, so a write tool returns a proposal and a person confirms it. The
 * arguments were validated when the proposal was made and are kept here, which
 * is what makes the confirmation safe: the browser sends back only an id, and the
 * work performed is the work that was proposed. A browser that edits the arguments
 * and confirms has edited a record nobody executes.
 *
 * `pending` is the only status that can be confirmed. Confirming moves the row to
 * `confirmed` in the same statement that claims it, so two concurrent confirms
 * cannot both perform the work.
 */
class AiActionProposal extends Model
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    protected $table = 'ai_action_proposals';

    protected $fillable = [
        'ai_conversation_id',
        'user_id',
        'tool',
        'arguments',
        'status',
        'expires_at',
        'executed_at',
    ];

    protected $casts = [
        'arguments' => 'array',
        'expires_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this proposal is still awaiting a decision.
     *
     * Expiry is checked here rather than by a scheduled sweep, so a proposal that
     * was never answered stops being executable the moment it lapses, with or
     * without a job having run.
     */
    public function isPending(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }
}

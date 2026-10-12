<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proactive suggestion, and the evidence behind it.
 *
 * ## The row is the record
 *
 * Keeping a suggestion after it has been shown is what makes the anti-intrusion
 * rules enforceable: a dismissal has to be remembered so the same problem is
 * not raised again, and a cooldown has to be measured from when it was last
 * said. None of that works if the suggestion only ever existed in a rendered
 * element.
 *
 * ## `search_vector` has no counterpart here
 *
 * Unlike a document chunk, a suggestion carries a `tsvector` in `evidence` only.
 * The reason is scale: this table holds a handful of rows per person per day,
 * and searching them is not what anyone does.
 */
class AiSuggestion extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'ai_suggestions';

    protected $fillable = [
        'user_id',
        'ai_conversation_id',
        'source_module',
        'source_operation',
        'trigger_category',
        'entity_type',
        'entity_reference',
        'evidence',
        'suggested_action',
        'relevance_score',
        'dedupe_key',
        'status',
        'expires_at',
        'resolved_at',
        'last_offered_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'relevance_score' => 'float',
        'expires_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_offered_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function isAccessibleBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id;
    }
}

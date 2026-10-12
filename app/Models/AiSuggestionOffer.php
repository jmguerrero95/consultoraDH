<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One interruption: a suggestion, shown to a person, at a moment.
 *
 * Append-only. Nothing updates or deletes a row here, because the value of this
 * table is precisely that it cannot be quietly rewritten: the daily ceiling is
 * computed from it, so an entry that could be removed would be an entry that
 * could hide an interruption.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $ai_suggestion_id
 * @property string $dedupe_key
 * @property string $source_module
 * @property string $source_operation
 * @property Carbon $offered_at
 */
class AiSuggestionOffer extends Model
{
    protected $table = 'ai_suggestion_offers';

    protected $fillable = [
        'user_id',
        'ai_suggestion_id',
        'dedupe_key',
        'source_module',
        'source_operation',
        'offered_at',
    ];

    protected $casts = [
        'offered_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(AiSuggestion::class, 'ai_suggestion_id');
    }
}

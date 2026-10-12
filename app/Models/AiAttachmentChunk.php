<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One retrievable piece of an attached document.
 *
 * `search_vector` is a GENERATED column maintained by PostgreSQL, so it is not
 * in `$fillable` and must never be written: a value supplied here would be
 * silently replaced by the database, and relying on an assignment that does
 * nothing is how an index ends up disagreeing with the data.
 */
class AiAttachmentChunk extends Model
{
    protected $table = 'ai_attachment_chunks';

    protected $fillable = [
        'ai_attachment_id',
        'ordinal',
        'locator',
        'content',
        'character_count',
    ];

    protected $casts = [
        'ordinal' => 'integer',
        'character_count' => 'integer',
    ];

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(AiAttachment::class, 'ai_attachment_id');
    }
}

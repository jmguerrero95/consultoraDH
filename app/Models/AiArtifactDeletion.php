<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One pending file removal for a deleted AI artifact.
 *
 * A row here means "the database no longer references this path, but the bytes
 * may still be on disk". It is written in the same transaction as the row
 * deletes and removed only once the bytes are gone, so the cleanup survives a
 * crash between `COMMIT` and the `unlink`. See the migration for the full
 * reasoning.
 *
 * Nothing sensitive is stored: a private storage path, a counter and a
 * truncated exception name.
 */
class AiArtifactDeletion extends Model
{
    protected $table = 'ai_artifact_deletions';

    protected $fillable = [
        'user_id',
        'storage_path',
        'attempts',
        'last_error',
        'available_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'available_at' => 'datetime',
    ];
}

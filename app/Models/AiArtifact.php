<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Private AI-generated artifact.
 *
 * Stored in private storage, served via authorized download endpoint.
 */
class AiArtifact extends Model
{
    protected $table = 'ai_artifacts';

    protected $fillable = [
        'user_id',
        'conversation_id',
        // Which background task produced it. Listed so a future create() cannot
        // silently drop the link, which is what happened with `ai_task_id` on
        // `ai_attachment`.
        'ai_task_id',
        'display_filename',
        'mime_type',
        'format',
        'storage_path',
        'size_bytes',
        'expires_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isAccessibleBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id;
    }
}

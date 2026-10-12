<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A file attached to a conversation.
 *
 * ## Ownership is the model, not a convention
 *
 * `isAccessibleBy` requires both the owner and, when the file is scoped to a
 * conversation, that the conversation is the one being asked about. That is
 * why the relationship check is a method rather than a `where user_id`
 * scattered through controllers: a query that filters on the owner but forgets
 * the conversation returns a document from a different conversation, which is
 * exactly the leak this file exists to make impossible to express by accident.
 */
class AiAttachment extends Model
{
    public const STATUS_PROCESSED = 'processed';

    public const STATUS_UNREADABLE = 'unreadable';

    public const STATUS_EMPTY = 'empty';

    protected $table = 'ai_attachments';

    protected $fillable = [
        'user_id',
        'ai_conversation_id',
        'storage_path',
        'original_name',
        'mime_type',
        'extension',
        'size_bytes',
        'detected_kind',
        'status',
        'status_note',
        'page_count',
        'chunk_count',
        'character_count',
        'preview',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'page_count' => 'integer',
        'chunk_count' => 'integer',
        'character_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AiAttachmentChunk::class, 'ai_attachment_id');
    }

    /**
     * Whether this person may read this file, optionally in this conversation.
     *
     * @param  int|null  $conversationId  the conversation the read is for;
     *                                   a file scoped to another conversation is
     *                                   not readable even by its own owner
     */
    public function isAccessibleBy(User $user, ?int $conversationId = null): bool
    {
        if ((int) $this->user_id !== (int) $user->id) {
            return false;
        }

        if ($conversationId === null) {
            return true;
        }

        return (int) ($this->ai_conversation_id ?? 0) === (int) $conversationId;
    }

    public function isReadable(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    /**
     * The shape the API returns.
     *
     * Deliberately without `storage_path`: a client that learns where a file
     * lives on disk can build a request against it, and the download endpoint
     * is the only thing that should ever read one.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->ai_conversation_id,
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'size_bytes' => $this->size_bytes,
            'status' => $this->status,
            'status_note' => $this->status_note,
            'page_count' => $this->page_count,
            'chunk_count' => $this->chunk_count,
            'character_count' => $this->character_count,
            'preview' => $this->preview,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
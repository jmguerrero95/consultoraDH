<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Support\SupportAttachmentKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessageAttachment extends Model
{
    use HasFactory;

    protected $table = 'support_message_attachments';

    protected $fillable = [
        'support_message_id',
        'conversation_id',
        'kind',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'uploaded_by_user_id',
        'source_channel',
    ];

    protected $casts = [
        'kind' => SupportAttachmentKind::class,
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * The conversation this attachment belongs to.
     *
     * Read directly rather than through the message, so an authorisation check
     * is one comparison against a column that is NOT NULL — an attachment can
     * never belong to "no conversation", so there is no ambiguous case to
     * decide about at read time.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'support_message_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}

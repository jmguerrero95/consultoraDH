<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Support\SupportMessageChannel;
use App\Domain\Support\SupportMessageKind;
use App\Domain\Support\SupportMessageSenderKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportMessage extends Model
{
    use HasFactory;

    protected $table = 'support_messages';

    protected $fillable = [
        'conversation_id',
        'author_user_id',
        'sender_kind',
        'message_kind',
        'channel',
        'body_text',
        'client_visible',
        'external_message_id',
        'ingress_fingerprint',
        'email_from',
        'email_to',
        'in_reply_to',
    ];

    protected $casts = [
        'sender_kind' => SupportMessageSenderKind::class,
        'message_kind' => SupportMessageKind::class,
        'channel' => SupportMessageChannel::class,
        'client_visible' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportMessageAttachment::class, 'support_message_id');
    }

    public function isClientVisible(): bool
    {
        return $this->client_visible;
    }

    public function isInternalNote(): bool
    {
        return $this->message_kind->is(SupportMessageKind::Note);
    }
}

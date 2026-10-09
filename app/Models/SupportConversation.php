<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\LocksRow;
use App\Domain\Support\SupportConversationPriority;
use App\Domain\Support\SupportConversationStatus;
use App\Domain\Support\SupportMessageChannel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportConversation extends Model
{
    use HasFactory, LocksRow;

    protected $table = 'support_conversations';

    protected $fillable = [
        'client_id',
        'queue_id',
        'assigned_to_user_id',
        'subject',
        'status',
        'priority',
        'origin_channel',
        'last_message_id',
        'last_message_at',
        'first_response_due_at',
        'next_staff_response_due_at',
        'resolution_due_at',
        'first_responded_at',
        'resolved_at',
        'resolved_by',
        'closed_at',
        'closed_by',
        'created_by_user_id',
    ];

    protected $casts = [
        'status' => SupportConversationStatus::class,
        'priority' => SupportConversationPriority::class,
        'origin_channel' => SupportMessageChannel::class,
        'last_message_at' => 'datetime',
        'first_response_due_at' => 'datetime',
        'next_staff_response_due_at' => 'datetime',
        'resolution_due_at' => 'datetime',
        'first_responded_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(SupportQueue::class, 'queue_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'last_message_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'conversation_id');
    }

    public function readStates(): HasMany
    {
        return $this->hasMany(SupportReadState::class, 'conversation_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportMessageAttachment::class);
    }

    public function replyTokens(): HasMany
    {
        return $this->hasMany(SupportReplyToken::class, 'conversation_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SupportDelivery::class, 'conversation_id');
    }

    public function slaEvents(): HasMany
    {
        return $this->hasMany(SupportSlaEvent::class, 'conversation_id');
    }

    public function isOpen(): bool
    {
        return $this->status->is(SupportConversationStatus::Open);
    }

    public function isWaitingStaff(): bool
    {
        return $this->status->is(SupportConversationStatus::WaitingStaff);
    }

    public function isWaitingClient(): bool
    {
        return $this->status->is(SupportConversationStatus::WaitingClient);
    }

    public function isResolved(): bool
    {
        return $this->status->is(SupportConversationStatus::Resolved);
    }

    public function isClosed(): bool
    {
        return $this->status->is(SupportConversationStatus::Closed);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function canReceiveClientMessage(): bool
    {
        return $this->status->canReceiveClientMessage();
    }
}

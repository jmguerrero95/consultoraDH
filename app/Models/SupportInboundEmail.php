<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Support\SupportInboundEmailStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportInboundEmail extends Model
{
    use HasFactory;

    protected $table = 'support_inbound_emails';

    protected $fillable = [
        'external_message_id',
        'ingress_fingerprint',
        'from_address',
        'to_address',
        'subject',
        'body_text',
        'status',
        'reason',
        'linked_conversation_id',
        'linked_by',
        'linked_at',
        'discarded_by',
        'discarded_at',
    ];

    protected $casts = [
        'status' => SupportInboundEmailStatus::class,
        'linked_at' => 'datetime',
        'discarded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function linkedConversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'linked_conversation_id');
    }

    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    public function discarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discarded_by');
    }

    public function isQuarantined(): bool
    {
        return $this->status === SupportInboundEmailStatus::Quarantined;
    }

    public function isLinked(): bool
    {
        return $this->status === SupportInboundEmailStatus::Linked;
    }

    public function isDiscarded(): bool
    {
        return $this->status === SupportInboundEmailStatus::Discarded;
    }
}

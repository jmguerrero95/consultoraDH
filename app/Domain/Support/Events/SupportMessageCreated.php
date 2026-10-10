<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\SupportMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportMessageCreated implements AuditableEvent, SubjectAware, ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SupportMessage $message
    ) {}

    public function action(): string
    {
        return match ($this->message->sender_kind->value) {
            'client' => 'support.message.client_received',
            'staff' => $this->message->message_kind->value === 'note'
                ? 'support.note.created'
                : 'support.message.staff_sent',
            default => 'support.message.created',
        };
    }

    public function subject(): object
    {
        return $this->message;
    }

    public function broadcastOn(): array
    {
        $conversation = $this->message->conversation;

        if (! $conversation) {
            return [];
        }

        // Internal notes only go to staff channel (separate from client-visible channel)
        if ($this->message->message_kind->value === 'note') {
            return [
                new PrivateChannel('support.staff.conversation.'.$conversation->id),
            ];
        }

        return [
            new PrivateChannel('support.conversation.'.$conversation->id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->message->load(['author', 'attachments']),
            'event' => 'message.created',
        ];
    }

    public function auditAction(): AuditAction
    {
        return match ($this->message->sender_kind->value) {
            'client' => AuditAction::SupportMessageClientReceived,
            'staff' => $this->message->message_kind->value === 'note'
                ? AuditAction::SupportNoteCreated
                : AuditAction::SupportMessageStaffSent,
            default => AuditAction::SupportMessageCreated,
        };
    }

    public function auditActor(): ?Authenticatable
    {
        return $this->message->author;
    }

    public function auditSubject(): ?Model
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
                'conversation_id' => $this->message->conversation_id,
                'sender_kind' => $this->message->sender_kind->value,
                'message_kind' => $this->message->message_kind->value,
                'has_attachments' => $this->message->attachments->isNotEmpty(),
            ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

use App\Domain\Audit\AuditableEvent;
use App\Models\SupportMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportMessageCreated extends AuditableEvent implements ShouldBroadcast
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
}
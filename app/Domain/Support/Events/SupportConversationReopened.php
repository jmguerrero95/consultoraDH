<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportConversationReopened implements AuditableEvent, SubjectAware, ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SupportConversation $conversation,
        public User $actor
    ) {}

    public function action(): string
    {
        return 'support.conversation.reopened';
    }

    public function subject(): object
    {
        return $this->conversation;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('support.conversation.'.$this->conversation->id),
            new PrivateChannel('support.queue.'.$this->conversation->queue_id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'conversation' => $this->conversation->fresh(['queue']),
            'event' => 'conversation.reopened',
        ];
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::SupportConversationReopened;
    }

    public function auditActor(): ?Authenticatable
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->conversation;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
                'queue_id' => $this->conversation->queue_id,
            ];
    }
}

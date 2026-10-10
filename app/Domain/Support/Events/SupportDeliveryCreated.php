<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class SupportDeliveryCreated implements AuditableEvent, SubjectAware
{
    public function __construct(
        public SupportConversation $conversation,
        public SupportMessage $message,
        public int $recipientUserId,
        public string $status
    ) {}

    public function action(): string
    {
        return 'support.delivery.'.$this->status;
    }

    public function subject(): object
    {
        return $this->message;
    }

    public function auditAction(): AuditAction
    {
        return AuditAction::SupportDeliveryRecorded;
    }

    public function auditActor(): ?Authenticatable
    {
        return null;
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
                'conversation_id' => $this->conversation->id,
                'message_id' => $this->message->id,
                'recipient_user_id' => $this->recipientUserId,
                'status' => $this->status,
            ];
    }
}

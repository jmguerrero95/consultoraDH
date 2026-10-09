<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

use App\Domain\Audit\AuditableEvent;
use App\Models\SupportConversation;
use App\Models\SupportMessage;

class SupportDeliveryCreated extends AuditableEvent
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
}

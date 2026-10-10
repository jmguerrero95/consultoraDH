<?php

declare(strict_types=1);

namespace App\Domain\Support;

enum SupportConversationStatus: string
{
    case Open = 'open';
    case WaitingStaff = 'waiting_staff';
    case WaitingClient = 'waiting_client';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public static function clientCreated(): self
    {
        return self::WaitingStaff;
    }

    public function onClientMessage(bool $isFirstMessage): self
    {
        return $this === self::WaitingStaff ? $this : self::WaitingStaff;
    }

    public function onStaffClientVisibleReply(): self
    {
        return self::WaitingClient;
    }

    public function onStaffInternalNote(): self
    {
        return $this;
    }

    public function onResolve(): self
    {
        return self::Resolved;
    }

    public function onClose(): self
    {
        return self::Closed;
    }

    public function onReopen(): self
    {
        return self::WaitingStaff;
    }

    public function canReceiveClientMessage(): bool
    {
        return $this !== self::Closed;
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }
}
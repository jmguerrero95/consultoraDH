<?php

declare(strict_types=1);

namespace App\Domain\Support;

enum SupportConversationPriority: string
{
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function isAtLeast(self $minimum): bool
    {
        $order = [
            self::Normal->value => 1,
            self::High->value => 2,
            self::Urgent->value => 3,
        ];

        return $order[$this->value] >= $order[$minimum->value];
    }
}

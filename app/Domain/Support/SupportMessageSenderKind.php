<?php

declare(strict_types=1);

namespace App\Domain\Support;

enum SupportMessageSenderKind: string
{
    case Staff = 'staff';
    case Client = 'client';
    case External = 'external';
    case System = 'system';
}

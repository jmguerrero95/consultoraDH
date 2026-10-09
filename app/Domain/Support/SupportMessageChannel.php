<?php

declare(strict_types=1);

namespace App\Domain\Support;

enum SupportMessageChannel: string
{
    case Portal = 'portal';
    case Staff = 'staff';
    case Email = 'email';
    case Automation = 'automation';
    case System = 'system';
}

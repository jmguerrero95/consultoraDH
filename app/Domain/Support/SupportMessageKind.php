<?php

declare(strict_types=1);

namespace App\Domain\Support;

enum SupportMessageKind: string
{
    case Message = 'message';
    case Note = 'note';
    case System = 'system';

    public function isClientVisible(): bool
    {
        return $this === self::Message || $this === self::System;
    }
}
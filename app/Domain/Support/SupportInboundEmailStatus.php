<?php

declare(strict_types=1);

namespace App\Domain\Support;

enum SupportInboundEmailStatus: string
{
    case Quarantined = 'quarantined';
    case Linked = 'linked';
    case Discarded = 'discarded';
}

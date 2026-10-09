<?php

declare(strict_types=1);

namespace App\Domain\Support;

enum SupportAttachmentKind: string
{
    case File = 'file';
    case Audio = 'audio';
}

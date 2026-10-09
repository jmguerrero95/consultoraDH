<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exceptions;

use RuntimeException;

final class UnknownReportFormat extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function for(string $value): self
    {
        return new self('unknown_report_format', sprintf('El formato «%s» no está soportado.', $value));
    }
}

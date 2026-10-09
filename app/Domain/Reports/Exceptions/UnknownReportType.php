<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exceptions;

use RuntimeException;

/**
 * A report type nobody defined.
 *
 * §51: the report catalogue is closed. A request naming a type that is not in the enum
 * is a client error, never a query against an arbitrary table.
 */
final class UnknownReportType extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function for(string $value): self
    {
        return new self('unknown_report_type', sprintf('El reporte «%s» no existe.', $value));
    }
}

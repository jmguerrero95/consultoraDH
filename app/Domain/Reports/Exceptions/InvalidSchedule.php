<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exceptions;

use RuntimeException;

/**
 * A report schedule that cannot describe a moment in time.
 *
 * §R1: the fields a cadence requires are required, and the ones it ignores are left null
 * rather than stored and silently unread.
 */
final class InvalidSchedule extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function missingDay(string $field, int $min, int $max): self
    {
        return new self(
            $field,
            sprintf('Para esta frecuencia es obligatorio indicar %s (entre %d y %d).', $field, $min, $max),
        );
    }

    public static function runTime(string $value): self
    {
        return new self('invalid_run_time', sprintf('La hora «%s» no tiene el formato HH:MM.', $value));
    }
}

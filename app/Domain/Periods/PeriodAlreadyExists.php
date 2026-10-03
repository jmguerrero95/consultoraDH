<?php

declare(strict_types=1);

namespace App\Domain\Periods;

/**
 * Somebody tried to create a month that already exists.
 *
 * Refused rather than merged, because "create the period for October" and "October
 * already exists" are different statements and the second one is a question, not an
 * instruction. Silently returning the existing period would let an operator believe
 * they had opened a month when they had done nothing.
 */
final class PeriodAlreadyExists extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forMonth(MonthlyPeriod $month): self
    {
        return new self(sprintf('El periodo %s ya existe.', $month->label()));
    }
}

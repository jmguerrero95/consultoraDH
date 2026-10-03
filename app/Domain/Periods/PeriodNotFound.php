<?php

declare(strict_types=1);

namespace App\Domain\Periods;

/**
 * A period that was asked for does not exist.
 *
 * A distinct exception rather than a null, because "the period does not exist" and
 * "the period exists and is closed" are different answers to different mistakes, and
 * the caller has to tell them apart to report either of them usefully.
 */
final class PeriodNotFound extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function withId(int $id): self
    {
        return new self(sprintf('No existe el periodo con identificador %d.', $id));
    }

    public static function forMonth(MonthlyPeriod $month): self
    {
        return new self(sprintf('No existe el periodo %s.', $month->label()));
    }
}

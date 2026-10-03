<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * Whether a cutoff day falls in the period's own month or in the next one.
 *
 * This is a business fact about how a contribution is counted, and A03 does not
 * know it. Both values exist because both occur in payroll practice, and choosing
 * one here would decide, silently, when every contribution is due for every client
 * in the portfolio.
 *
 * So the operator configures it, and a month with no configured rule is a blocker
 * rather than a guess. `0` and `1` are the only values: a cutoff two or more months
 * ahead is not a thing this system models, and allowing it would only produce dates
 * nobody expects.
 */
enum CutoffMonthOffset: int
{
    /** The cutoff day falls in the same calendar month as the period. */
    case SameMonth = 0;

    /** The cutoff day falls in the following calendar month. */
    case FollowingMonth = 1;

    public function label(): string
    {
        return match ($this) {
            self::SameMonth => 'Mismo mes',
            self::FollowingMonth => 'Mes siguiente',
        };
    }

    public static function fromInt(int $value): self
    {
        return self::tryFrom($value) ?? throw new \InvalidArgumentException(sprintf(
            'El desplazamiento de mes de corte debe ser 0 o 1; se recibió %d.',
            $value,
        ));
    }
}

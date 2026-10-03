<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * How late an obligation is, relative to an explicit date.
 *
 * Days are calendar days after `due_on`, and the boundary belongs to the later
 * bucket: an obligation due exactly 30 days ago is `1_30`, and 31 days is
 * `31_60`. Each bucket therefore covers the days its name suggests and no others,
 * which is the only way two people can compare notes about the same debt.
 *
 * `not_due` is not lateness at all, and it is a bucket rather than an absence so
 * that a portfolio total can be read without a separate query.
 */
enum AgingBucket: string
{
    case NotDue = 'not_due';
    case OneToThirty = '1_30';
    case ThirtyOneToSixty = '31_60';
    case SixtyOneToNinety = '61_90';
    case OverNinety = 'over_90';

    public function label(): string
    {
        return match ($this) {
            self::NotDue => 'No vencida',
            self::OneToThirty => '1 a 30 días',
            self::ThirtyOneToSixty => '31 a 60 días',
            self::SixtyOneToNinety => '61 a 90 días',
            self::OverNinety => 'Más de 90 días',
        };
    }

    /**
     * @param  int  $daysLate  calendar days after `due_on`; zero or less is not due
     */
    public static function forDaysLate(int $daysLate): self
    {
        return match (true) {
            $daysLate <= 0 => self::NotDue,
            $daysLate <= 30 => self::OneToThirty,
            $daysLate <= 60 => self::ThirtyOneToSixty,
            $daysLate <= 90 => self::SixtyOneToNinety,
            default => self::OverNinety,
        };
    }
}

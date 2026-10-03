<?php

declare(strict_types=1);

namespace App\Domain\Periods;

use Illuminate\Support\Carbon;

/**
 * A calendar month, as a value.
 *
 * A monthly period in this system is identified by the first day of the month:
 * `2026-10-01` is October 2026. Storing the first day rather than a year/month
 * pair means the column is a real date, sorts correctly, and can be compared with
 * the A02 interval columns without conversion.
 *
 * The arithmetic lives here so no controller invents it. Every caller asks this
 * object for the boundaries instead of adding months to a string, and the tests
 * cover the awkward months once rather than once per screen.
 *
 * ## The interval
 *
 *     [startsOn, endsOnExclusive)
 *
 * The same half-open convention as `EffectivePeriod`, and deliberately so. A
 * relationship that ends on 2026-11-01 is inside October and outside November,
 * and there is no day that belongs to neither month and no day in two.
 *
 * ## There is no default month
 *
 * Nothing in this class resolves "the current period" from the clock. That is
 * `MonthlyPeriodResolver`'s job, and it needs the database to answer it. A value
 * object that quietly returned today's month would let a caller believe it had
 * asked the system when it had read a clock.
 */
final readonly class MonthlyPeriod
{
    private function __construct(
        public Carbon $firstDay,
    ) {}

    public static function fromFirstDay(Carbon|string $date): self
    {
        $parsed = $date instanceof Carbon
            ? $date->copy()
            : Carbon::parse($date);

        // A time component would make two rows for the same month unequal, and the
        // column is a date, so the time is dropped here rather than at each write.
        $parsed->startOfDay();

        return new self($parsed);
    }

    /**
     * Build from a year and a month, both 1-based as humans count.
     *
     * Refused rather than adjusted when the month is out of range: `2026-13` is a
     * mistake, and correcting it to January of the next year would silently invent
     * a period nobody asked for.
     */
    public static function fromYearMonth(int $year, int $month): self
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException(sprintf(
                'El mes debe estar entre 1 y 12; se recibió %d.',
                $month,
            ));
        }

        if ($year < 1900 || $year > 2200) {
            throw new \InvalidArgumentException(sprintf(
                'El año debe ser razonable; se recibió %d.',
                $year,
            ));
        }

        return new self(Carbon::create($year, $month, 1)->startOfDay());
    }

    /**
     * Parse the `YYYY-MM` form used by the interface and the API.
     */
    public static function fromKey(string $key): self
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', trim($key), $matches) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'El periodo debe escribirse como AAAA-MM; se recibió «%s».',
                $key,
            ));
        }

        return self::fromYearMonth((int) $matches[1], (int) $matches[2]);
    }

    /**
     * Build from a `YYYY-MM` key if the value looks like one, otherwise from a date.
     *
     * Convenient for a request parameter, where a person may send either, and
     * refusing to guess between them is not an option worth the strictness: the two
     * forms are unambiguous by shape.
     */
    public static function parse(string $value): self
    {
        $trimmed = trim($value);

        return preg_match('/^\d{4}-\d{2}$/', $trimmed) === 1
            ? self::fromKey($trimmed)
            : self::fromFirstDay($trimmed);
    }

    /** `2026-10`. The stable identifier used by routes, keys and audit metadata. */
    public function key(): string
    {
        return $this->firstDay->format('Y-m');
    }

    /** The first day of the month, which is also how the row is stored. */
    public function startsOn(): Carbon
    {
        return $this->firstDay->copy();
    }

    /** The first day of the next month. Excluded from this period. */
    public function endsOnExclusive(): Carbon
    {
        return $this->firstDay->copy()->addMonthNoOverflow()->startOfMonth();
    }

    /** The last day of the month, inclusive. Useful for display, never for bounds. */
    public function lastDay(): Carbon
    {
        return $this->firstDay->copy()->endOfMonth()->startOfDay();
    }

    public function year(): int
    {
        return $this->firstDay->year;
    }

    public function month(): int
    {
        return $this->firstDay->month;
    }

    /** Spanish label: `Octubre 2026`. */
    public function label(): string
    {
        // The month name is formatted in Spanish explicitly rather than relying on
        // the process locale, which is English in the containers. A month labelled in
        // the wrong language is small, and it is the kind of small that only shows up
        // in production screenshots.
        $months = [
            1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
        ];

        return sprintf('%s %d', $months[$this->firstDay->month], $this->firstDay->year);
    }

    public function isSameAs(self $other): bool
    {
        return $this->firstDay->equalTo($other->firstDay);
    }

    public function isBefore(self $other): bool
    {
        return $this->firstDay->lt($other->firstDay);
    }

    public function isAfter(self $other): bool
    {
        return $this->firstDay->gt($other->firstDay);
    }

    public function equalsDate(Carbon|string $date): bool
    {
        return $this->firstDay->isSameDay($date instanceof Carbon ? $date : Carbon::parse($date));
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'starts_on' => $this->startsOn()->format('Y-m-d'),
            'ends_on_exclusive' => $this->endsOnExclusive()->format('Y-m-d'),
            'label' => $this->label(),
        ];
    }

    public function __toString(): string
    {
        return $this->key();
    }
}

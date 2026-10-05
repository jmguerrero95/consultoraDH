<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use Illuminate\Support\Carbon;

/**
 * The date a person decided for one source line, or the explicit statement that nobody did.
 *
 * ## Why this is a type and not a nullable array
 *
 * §8.3 forbids presenting a month as a day, and the plan builder has three states to express,
 * not two: a date with day precision, a date with month precision, and *no date at all* with
 * `precision = unknown`. A nullable `['date' => …, 'precision' => …]` lets those three be
 * confused in both directions — a `precision` of `unknown` with a date, or a null date with a
 * precision — and the audit found exactly that class of confusion in the codebase already:
 * `HistoricalInterval` needed a whole docblock to explain why its fields exist, because the
 * builder had been free to build an impossible interval.
 *
 * Returning an object forces the caller to ask which of the three it has.
 *
 * ## `undecided` is a real answer
 *
 * Most lines never raise `invalid_affiliation_date`, so most calls return `undecided()` and the
 * caller keeps the parsed value untouched. Making that explicit means "no decision" cannot be
 * mistaken for "decided unknown" — the difference between a month with no date evidence and a
 * month where a person declared the date unknowable, which §8.4 treats differently.
 */
final readonly class ResolvedDate
{
    private function __construct(
        public ?string $date,
        public ?string $precision,
    ) {}

    /** Nobody answered: keep what the parser read. */
    public static function undecided(): self
    {
        return new self(null, null);
    }

    /** §8.4's `unknown`: `started_on = null`, `precision = unknown`. */
    public static function unknown(): self
    {
        return new self(null, HistoricalInterval::UNKNOWN);
    }

    /**
     * A date a person asserted, with the precision they asserted it at.
     *
     * @param  string  $precision  `day` or `month`
     */
    public static function known(string $date, string $precision): self
    {
        return new self($date, $precision);
    }

    public function isDecided(): bool
    {
        return $this->precision !== null;
    }

    public function isUnknownStart(): bool
    {
        return $this->date === null && $this->precision === HistoricalInterval::UNKNOWN;
    }

    /** The ISO date, or NULL when the answer is "unknown". */
    public function isoDate(): ?string
    {
        return $this->date;
    }

    /**
     * Build an interval start from this answer.
     *
     * `unknown()` maps to `fromUnknownStart()` and a decided date to `startingAt()`, so the
     * interval can never end up with a null start and a day precision.
     */
    public function asIntervalStart(): HistoricalInterval
    {
        return $this->isUnknownStart()
            ? HistoricalInterval::fromUnknownStart()
            : HistoricalInterval::fromUnknownStart()->startingAt(
                Carbon::parse((string) $this->date),
                $this->precision === HistoricalInterval::MONTH
                    ? HistoricalInterval::MONTH
                    : HistoricalInterval::DAY,
            );
    }
}

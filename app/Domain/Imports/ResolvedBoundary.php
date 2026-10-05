<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use Illuminate\Support\Carbon;

/**
 * A boundary a person chose, at the precision they chose it.
 *
 * §8.5 asks a person to decide where one overlapping episode stops and the next begins. The
 * answer is a date *and* how precise that date is, and the two cannot be separated: a boundary
 * of `2026-03-01` chosen as "the month it changed" is a different record from the same date
 * chosen as "the day it changed", and §8.3 says the second may not be claimed from monthly
 * snapshots.
 *
 * `IssueResolution` already rejects a `month` precision whose day is not the first, so a
 * `ResolvedBoundary` cannot be built in a shape the interval builder has to guess about.
 */
final readonly class ResolvedBoundary
{
    public function __construct(
        public string $date,
        public string $precision,
    ) {}

    public function carbon(): Carbon
    {
        return Carbon::parse($this->date);
    }

    /** §8.3's label, so the boundary reads the way the reviewer wrote it. */
    public function describe(): string
    {
        return HistoricalInterval::describe($this->carbon(), $this->precision);
    }

    /** Apply this boundary as the end of one interval and the start of the next. */
    public function applyTo(HistoricalInterval $interval): HistoricalInterval
    {
        return $interval->endingOn($this->carbon(), $this->precision);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['boundary' => $this->date, 'precision' => $this->precision];
    }
}

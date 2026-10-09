<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Reports\Exceptions\InvalidSchedule;
use Carbon\CarbonImmutable;

/**
 * The first future moment a report schedule should run, and every moment after it.
 *
 * ## The defect this closes
 *
 * Creation wrote `next_run_at = now()->addHour()` regardless of what the user had asked
 * for. A weekly schedule for Monday at 07:00 would first run an hour after it was saved —
 * on whatever day that happened to be. The schedule the user entered described the *second*
 * occurrence and nothing described the first.
 *
 * ## Why one pure calculator
 *
 * Creation and recurrence both need this rule, and two implementations of it would drift
 * in exactly the way that produced the original defect: creation computing "now + 1h"
 * and recurrence computing the real cadence. So it is one pure function, with no clock and
 * no database — the caller passes "now" — and both paths call it.
 *
 * ## The one rule worth stating
 *
 * **A monthly schedule on a day the month does not have runs on that month's last day.**
 * "The 31st" therefore means "the last day" in February, and a monthly report on the 31st
 * still arrives in a short month instead of being skipped for a month. This is the only
 * ambiguity resolved here, and it is resolved once.
 */
final class ReportScheduleCalculator
{
    /**
     * @param  string  $runTime  `HH:MM`
     */
    public static function firstOccurrence(
        ReportCadence $cadence,
        string $runTime,
        ?int $dayOfWeek,
        ?int $dayOfMonth,
        CarbonImmutable $now,
    ): CarbonImmutable {
        [$hour, $minute] = self::timeParts($runTime);

        $candidate = match ($cadence) {
            ReportCadence::Daily => $now->setTime($hour, $minute),

            // `startOfWeek(SUNDAY)` lands on Sunday, which is PHP's `N = 0`, so the
            // offset from there is exactly `day_of_week`. Starting from Monday and
            // offsetting by `N` instead is off by one and silently runs the wrong day.
            ReportCadence::Weekly => $now
                ->startOfWeek(CarbonImmutable::SUNDAY)
                ->addDays(self::requireDay($dayOfWeek, 'day_of_week', 0, 6))
                ->setTime($hour, $minute),

            // The documented rule, applied by clamping: day 29–31 in a shorter month
            // lands on that month's last day. `startOfMonth()->day(31)` would overflow
            // into the following month instead.
            ReportCadence::Monthly => $now
                ->startOfMonth()
                ->day(min(
                    self::requireDay($dayOfMonth, 'day_of_month', 1, 31),
                    (int) $now->format('t'),
                ))
                ->setTime($hour, $minute),
        };

        // Strictly future: a schedule saved for 07:00 at 07:00 runs tomorrow, not twice
        // today. The step is the cadence's own unit, so a weekly schedule rolls a week
        // rather than landing on the next day.
        if ($candidate->greaterThan($now)) {
            return $candidate;
        }

        return self::nextAfter($cadence, $runTime, $dayOfWeek, $dayOfMonth, $candidate);
    }

    /** The next occurrence strictly after `$from`. */
    public static function nextAfter(
        ReportCadence $cadence,
        string $runTime,
        ?int $dayOfWeek,
        ?int $dayOfMonth,
        CarbonImmutable $from,
    ): CarbonImmutable {
        $advance = match ($cadence) {
            ReportCadence::Daily => $from->addDay(),
            ReportCadence::Weekly => $from->addWeek(),
            ReportCadence::Monthly => $from->addMonthNoOverflow(),
        };

        // Monthly re-derives its day from the **intended** day of the schedule, never
        // from the date the previous run happened to land on. Reading it back off `$from`
        // is the bug that makes a day-31 schedule stick at the 28th forever: February
        // clamps 31 to 28, and the next run would then carry 28 forward into March.
        return match ($cadence) {
            ReportCadence::Monthly => $advance
                ->setDay(min((int) $dayOfMonth, (int) $advance->daysInMonth)),
            default => $advance,
        };
    }

    /**
     * The fields a cadence requires, and rejects the ones it ignores.
     *
     * @throws InvalidSchedule
     */
    public static function assertValid(ReportCadence $cadence, ?int $dayOfWeek, ?int $dayOfMonth): void
    {
        match ($cadence) {
            ReportCadence::Daily => null,
            ReportCadence::Weekly => self::requireDay($dayOfWeek, 'day_of_week', 0, 6),
            ReportCadence::Monthly => self::requireDay($dayOfMonth, 'day_of_month', 1, 31),
        };
    }

    /** @return array{0: int, 1: int} */
    private static function timeParts(string $runTime): array
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($runTime), $matches)) {
            throw InvalidSchedule::runTime($runTime);
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    private static function requireDay(?int $day, string $field, int $min, int $max): int
    {
        if ($day === null || $day < $min || $day > $max) {
            throw InvalidSchedule::missingDay($field, $min, $max);
        }

        return $day;
    }
}

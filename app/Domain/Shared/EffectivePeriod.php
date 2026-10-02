<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use Illuminate\Database\Eloquent\Builder;

/**
 * What `started_on` and `ended_on` mean, in one place.
 *
 * Both historical tables in this system describe a period with two dates, and a
 * period is written the same way in both:
 *
 *     [started_on, ended_on)
 *
 * `started_on` is the first day the record is effective. `ended_on` is the first
 * day it is **no longer** effective. The end date is excluded from the period.
 * NULL means the record has not ended yet.
 *
 * The alternative convention, `[started_on, ended_on]`, is used by a great many
 * systems and the two are indistinguishable until the moment they matter: a
 * transfer effective on the first of March. Under an inclusive end it has to be
 * written as the previous day, which shifts the arithmetic into every caller and
 * gets one of them wrong. Under an exclusive end the closing date and the opening
 * date are the same day, so the day before belongs to the old period, the effective
 * day belongs to the new one, and there is no day that belongs to neither.
 *
 * A02 already behaved this way: a transfer writes `ended_on` on the closed row and
 * `started_on` on the opened row, both set to the effective date. What was missing
 * was the statement of the rule, the query that implements it, and the tests at
 * the boundary. A03 will report on periods; it has to inherit this convention
 * rather than invent one, so it lives in the domain and is named here.
 *
 * @see ClientCompanyAssignment::activeOn()
 * @see ClientAffiliation::activeOn()
 */
final class EffectivePeriod
{
    /**
     * Whether a period with these bounds is effective on a date.
     *
     * The same three comparisons as the scopes, so a caller in PHP and a query in
     * SQL cannot disagree about the boundary.
     */
    public static function covers(
        \DateTimeInterface|string|null $startedOn,
        \DateTimeInterface|string|null $endedOn,
        \DateTimeInterface|string $date,
    ): bool {
        $day = self::day($date);

        if ($startedOn === null) {
            return false;
        }

        if (self::day($startedOn) > $day) {
            return false;
        }

        return $endedOn === null || self::day($endedOn) > $day;
    }

    /**
     * The SQL for `covers()`, applied to a query with `started_on` and `ended_on`.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function scopeActiveOn($query, \DateTimeInterface|string $date): void
    {
        $day = self::day($date);

        // `started_on <= the day`, and `ended_on` either absent or after it. The
        // comparison is on dates, not timestamps, because the domain stores dates
        // and a timestamp would push a period that started that morning out of the
        // day it started.
        $query->where(function ($query) use ($day): void {
            $query->whereNull('started_on')->orWhere('started_on', '<=', $day);
        })->where(function ($query) use ($day): void {
            $query->whereNull('ended_on')->orWhere('ended_on', '>', $day);
        });
    }

    /**
     * A date as `yyyy-mm-dd`, whichever type arrived.
     */
    public static function day(\DateTimeInterface|string $value): string
    {
        if (is_string($value)) {
            return substr($value, 0, 10);
        }

        return $value->format('Y-m-d');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Periods;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Which period the system considers current.
 *
 * The question deserves a written answer because several periods may be open at
 * once, deliberately: an earlier month can stay open while it is being corrected
 * while a newer month is already being billed, and refusing that would force an
 * operator to close a month they are not finished with in order to open one they
 * are.
 *
 * So "current" is resolved in this order, and each step has a reason:
 *
 *   1. **the period matching the current calendar month, when it exists**
 *      This is what "current" means to a person. If October exists, October is
 *      current, even while September is still open.
 *
 *   2. **otherwise the most recent open period**
 *      On the first of the month, before anybody has created it, the newest open
 *      period is the one being worked on. Answering "none" would make the screen
 *      useless for the first few days of every month, and inventing the new month
 *      would create a period nobody asked for.
 *
 *   3. **otherwise nothing**
 *      No periods exist, or all are closed. There is no current period and the
 *      answer says so.
 *
 * A closed period is never current: a month that has been settled is not where work
 * happens.
 */
final class MonthlyPeriodResolver
{
    /**
     * The current period, or null when there is nothing open to work on.
     *
     * @param  Carbon|null  $today  injectable so a test can ask about a historical
     *                              date without waiting for the calendar
     */
    public function current(?Carbon $today = null): ?\App\Models\MonthlyPeriod
    {
        $today ??= now();
        $thisMonth = MonthlyPeriod::fromFirstDay($today);

        $exact = \App\Models\MonthlyPeriod::query()
            ->where('period_month', $thisMonth->startsOn())
            ->open()
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        return \App\Models\MonthlyPeriod::query()
            ->open()
            ->orderByDesc('period_month')
            ->first();
    }

    /**
     * Every period whose obligations could still be generated, newest first.
     *
     * @return Collection<int, \App\Models\MonthlyPeriod>
     */
    public function openPeriods(): Collection
    {
        return \App\Models\MonthlyPeriod::query()
            ->open()
            ->orderByDesc('period_month')
            ->get();
    }

    /**
     * The period, if it exists, for a given month.
     */
    public function forMonth(MonthlyPeriod $month): ?\App\Models\MonthlyPeriod
    {
        return \App\Models\MonthlyPeriod::query()
            ->where('period_month', $month->startsOn())
            ->first();
    }

    /**
     * Lock a period row for a structural change.
     *
     * The lock is what serialises generation, closing and reopening for one month
     * against each other. Two operators generating the same month at the same moment
     * would otherwise both read the same set of relationships and both insert, and
     * the unique index would turn the second into an error rather than into a
     * no-op, which is a worse outcome than either of them deciding it first.
     *
     * A row lock rather than an advisory one because the row is the thing being
     * changed: whoever holds it is the one whose state the others will read.
     */
    public function lockForChange(\App\Models\MonthlyPeriod $period): \App\Models\MonthlyPeriod
    {
        $locked = \App\Models\MonthlyPeriod::query()
            ->lockForUpdate()
            ->find($period->getKey());

        if ($locked === null) {
            throw new \RuntimeException('El periodo ya no existe.');
        }

        return $locked;
    }

    /**
     * Lock a period row and re-read it, so every decision is made from the copy
     * taken after the wait rather than from the instance the caller was handed.
     */
    public function lockByIdForChange(int $periodId): \App\Models\MonthlyPeriod
    {
        $locked = \App\Models\MonthlyPeriod::query()
            ->lockForUpdate()
            ->find($periodId);

        if ($locked === null) {
            throw PeriodNotFound::withId($periodId);
        }

        return $locked;
    }

    /**
     * The months between two periods, inclusive, newest first.
     *
     * Used by the receivables screen to name the exact months a client owes, and by
     * the interface to draw a range of periods. Bounded so a stray input cannot ask
     * for a thousand months and hold a thousand rows.
     *
     * @return list<MonthlyPeriod>
     */
    public function monthsBetween(MonthlyPeriod $from, MonthlyPeriod $to, int $limit = 240): array
    {
        $months = [];
        $cursor = $from;
        $guard = 0;

        while (! $cursor->isAfter($to) && $guard < $limit) {
            $months[] = $cursor;
            $cursor = MonthlyPeriod::fromFirstDay($cursor->startsOn()->addMonthNoOverflow());
            $guard++;
        }

        return array_reverse($months);
    }
}

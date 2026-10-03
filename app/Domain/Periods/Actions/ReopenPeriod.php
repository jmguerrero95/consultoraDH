<?php

declare(strict_types=1);

namespace App\Domain\Periods\Actions;

use App\Domain\Periods\Events\PeriodReopened;
use App\Domain\Periods\MonthlyPeriodResolver;
use App\Domain\Periods\PeriodAlreadyOpen;
use App\Domain\Periods\PeriodStatus;
use App\Domain\Periods\ReopenReasonRequired;
use App\Models\MonthlyPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reopen a closed month, with a reason.
 *
 * ## Reopening is exceptional
 *
 * Closing says "this is what we billed". Reopening takes that back, so it asks for
 * two things a close does not: a reason, written into the period and into the audit
 * trail, and the `periods.reopen` permission, which the Operations role does not
 * hold by default. Both exist because the first question anybody asks about a
 * corrected month is why it was corrected.
 *
 * ## Reopening changes nothing else
 *
 * It does not delete payments, allocations or adjustments. Those are the record of
 * money that actually moved, and removing them because a month was reopened would
 * destroy evidence rather than correct a mistake.
 *
 * It does not regenerate anything. Reopening makes generation *possible* again; the
 * operator runs it, sees the preview, and decides. An automatic regeneration on
 * reopen would rewrite base amounts and due dates behind the operator's back, which
 * is the silent recalculation this module exists to prevent.
 *
 * The `generation_performed_at` marker is left alone deliberately: the fact that
 * generation ran before the month was closed is still true, and clearing it would
 * make a closed month that generated nothing look untouched.
 */
final class ReopenPeriod
{
    public function __construct(
        private readonly MonthlyPeriodResolver $periods,
    ) {}

    public function execute(MonthlyPeriod $period, User $actor, string $reason): MonthlyPeriod
    {
        $reason = trim($reason);

        // A reason that is only whitespace is no reason. Refused rather than stored,
        // because an empty string in the audit trail reads as "no reason was needed".
        if ($reason === '') {
            throw new ReopenReasonRequired;
        }

        return DB::transaction(function () use ($period, $actor, $reason): MonthlyPeriod {
            $locked = $this->periods->lockByIdForChange($period->id);

            if ($locked->isOpen()) {
                throw new PeriodAlreadyOpen($locked->label());
            }

            $locked->forceFill([
                'status' => PeriodStatus::Open->value,
                'closed_at' => null,
                'closed_by' => null,
                'reopened_at' => now(),
                'reopened_by' => $actor->id,
                'last_reopen_reason' => $reason,
            ])->save();

            event(new PeriodReopened($locked->refresh(), $actor, $reason));

            return $locked->refresh();
        });
    }
}

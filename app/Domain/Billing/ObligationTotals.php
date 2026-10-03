<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\MonthlyObligation;
use Illuminate\Support\Carbon;

/**
 * The money of one obligation, derived in one place.
 *
 * ## The formula
 *
 *     effective_amount_cop = base_amount_cop + sum(active adjustment delta_cop)
 *     paid_amount_cop       = sum(live allocations from payments that are not voided)
 *     balance_cop           = effective_amount_cop - paid_amount_cop
 *
 * Three lines, and the reason they are written down here rather than in each caller
 * is that every screen, every validation and every domain action has to agree on
 * them exactly. A balance computed two ways is two answers, and the discrepancy
 * shows up as a figure nobody can explain rather than as an error anybody sees.
 *
 * ## Nothing here is stored
 *
 * There is no `balance_cop` column. A stored balance has to be updated by every
 * operation that can affect it, and each of those is a chance to forget one: a
 * reversed allocation, a voided payment, a new adjustment. Deriving it means the
 * answer cannot go stale, because there is nothing to go stale.
 *
 * For lists, `ObligationBalanceQuery` computes the same formula in SQL so a page of
 * a hundred obligations does not become three hundred queries. The two
 * implementations are held to each other by a parity test, which is the only reason
 * having both is safe.
 *
 * ## What counts as paid
 *
 * Only allocations whose payment is **not voided**. A voided payment's allocations
 * remain in the database, because they are the record of what somebody originally
 * did, but they stop counting: the money did not arrive, so it does not pay
 * anything.
 *
 * @phpstan-type Totals array{
 *     effective_amount_cop: int,
 *     paid_amount_cop: int,
 *     balance_cop: int,
 *     settlement_state: SettlementState,
 *     is_overdue: bool,
 *     days_late: int,
 *     aging_bucket: AgingBucket
 * }
 */
final readonly class ObligationTotals
{
    public function __construct(
        public int $effectiveAmount,
        public int $paidAmount,
        public Carbon $dueOn,
    ) {}

    /**
     * Build from the amounts a caller has already aggregated.
     *
     * The public entry point, because aggregating in SQL and aggregating in PHP
     * have to reach the same object. Passing the sums in is what lets the parity test
     * compare the two implementations at all.
     */
    public static function fromAmounts(int $baseAmount, int $adjustmentsTotal, int $paidAmount, Carbon $dueOn): self
    {
        return new self($baseAmount + $adjustmentsTotal, $paidAmount, $dueOn);
    }

    public static function for(MonthlyObligation $obligation, int $adjustmentsTotal, int $paidAmount): self
    {
        return new self(
            $obligation->base_amount_cop + $adjustmentsTotal,
            $paidAmount,
            $obligation->due_on,
        );
    }

    public function balance(): int
    {
        return $this->effectiveAmount - $this->paidAmount;
    }

    public function settlementState(): SettlementState
    {
        return SettlementState::for($this->effectiveAmount, $this->paidAmount);
    }

    /**
     * Whether this obligation is late, as of a date.
     *
     * `as_of` defaults to today, but never implicitly in the domain: callers that
     * care about a historical view pass the date they mean. A debt due today is not
     * yet overdue, which is why the comparison is strict.
     */
    public function isOverdueAsOf(Carbon $asOf): bool
    {
        return $this->balance() > 0 && $asOf->startOfDay()->gt($this->dueOn->copy()->startOfDay());
    }

    public function isOverdue(?Carbon $asOf = null): bool
    {
        return $this->isOverdueAsOf($asOf ?? now());
    }

    /** Calendar days after the due date. Zero or less when not yet due. */
    /**
     * Calendar days between the due date and the day being reported.
     *
     * Measured from the due date forward. With the arguments the other way round the
     * answer is positive for a due date still in the future, so an obligation nobody
     * owes yet reports two hundred days late and lands in the oldest bucket.
     */
    public function daysLateAsOf(Carbon $asOf): int
    {
        return (int) $this->dueOn->copy()->startOfDay()->diffInDays($asOf->startOfDay(), false);
    }

    public function agingBucketAsOf(Carbon $asOf): AgingBucket
    {
        $days = $this->daysLateAsOf($asOf);

        // Only an obligation with money outstanding is aged. A paid one is not late
        // however long ago it fell due, which is what makes this different from a
        // simple date difference.
        if ($this->balance() <= 0) {
            return AgingBucket::NotDue;
        }

        return AgingBucket::forDaysLate($days);
    }

    /**
     * The complete picture, for a response.
     *
     * @return Totals
     */
    public function toArray(Carbon $asOf): array
    {
        return [
            'effective_amount_cop' => $this->effectiveAmount,
            'paid_amount_cop' => $this->paidAmount,
            'balance_cop' => $this->balance(),
            'settlement_state' => $this->settlementState()->value,
            'is_overdue' => $this->isOverdueAsOf($asOf),
            'days_late' => max(0, $this->daysLateAsOf($asOf)),
            'aging_bucket' => $this->agingBucketAsOf($asOf)->value,
        ];
    }
}

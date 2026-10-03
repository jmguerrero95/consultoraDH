<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\AdjustmentRejected;
use App\Domain\Billing\AdjustmentType;
use App\Domain\Billing\Events\ObligationAdjusted;
use App\Domain\Billing\Events\ObligationAdjustmentReversed;
use App\Models\MonthlyObligation;
use App\Models\ObligationAdjustment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Post corrections against an obligation, and undo them.
 *
 * ## The base amount is not edited
 *
 * There is no path here that changes `base_amount_cop`. An obligation says what was
 * generated and when; a correction says what should have been generated, and lives
 * beside it. Editing the base would make the original generation unrecoverable, and
 * the question "was March billed at 200000 or 235000?" would have no answer.
 *
 * ## Reversal, not deletion
 *
 * A posted adjustment is never edited and never deleted. Undoing one writes a second
 * row with the opposite sign and a pointer to the first, so the ledger shows both
 * the correction and its withdrawal. An adjustment that was edited in place would
 * leave no evidence that a correction had been needed at all.
 *
 * An adjustment may be reversed once. The second attempt is refused, because a
 * double reversal would cancel the original twice over and quietly change the
 * balance by an amount nobody chose.
 *
 * ## Two limits, both about overpayment
 *
 * An adjustment may not take the effective amount below zero, and may not take it
 * below what has already been paid against it. The second is the one that matters:
 * reducing an obligation that has been paid creates a negative balance, which is an
 * overpayment the system has no way to represent honestly. When it would happen, the
 * correct move is to correct the payment allocation first, and this refuses so the
 * operator finds that out before the ledger is wrong.
 *
 * ## Lock order
 *
 *     OBLIGATION  ->  its live allocations
 *
 * The obligation is the row both decisions are about. The allocations are read after
 * it and are not locked: they cannot change the answer without taking the
 * obligation's lock first, because every write path does so.
 */
final class AdjustObligation
{
    /**
     * Post a correction.
     *
     * @throws AdjustmentRejected
     */
    public function execute(
        MonthlyObligation $obligation,
        AdjustmentType $type,
        int $deltaCop,
        string $reason,
        User $actor,
    ): ObligationAdjustment {
        $reason = trim($reason);

        if ($reason === '') {
            throw AdjustmentRejected::reasonRequired();
        }

        if ($deltaCop === 0) {
            throw AdjustmentRejected::zeroDelta();
        }

        if ($type->isReversal()) {
            throw AdjustmentRejected::reversalIsNotAnOrdinaryAdjustment();
        }

        return DB::transaction(function () use ($obligation, $type, $deltaCop, $reason, $actor): ObligationAdjustment {
            $locked = $this->lock($obligation);

            $adjustmentsTotal = (int) ObligationAdjustment::query()
                ->where('obligation_id', $locked->id)
                ->sum('delta_cop');

            $paid = $this->paidAmount($locked);

            $effectiveAfter = $locked->base_amount_cop + $adjustmentsTotal + $deltaCop;

            if ($effectiveAfter < 0) {
                throw AdjustmentRejected::wouldGoBelowZero($deltaCop);
            }

            if ($paid > $effectiveAfter) {
                throw AdjustmentRejected::wouldOverpay($paid, $effectiveAfter);
            }

            $adjustment = ObligationAdjustment::query()->create([
                'obligation_id' => $locked->id,
                'type' => $type->value,
                'delta_cop' => $deltaCop,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);

            event(new ObligationAdjusted($adjustment, $actor));

            return $adjustment;
        });
    }

    /**
     * Undo a posted adjustment.
     *
     * @throws AdjustmentRejected
     */
    public function reverse(
        ObligationAdjustment $adjustment,
        string $reason,
        User $actor,
    ): ObligationAdjustment {
        $reason = trim($reason);

        if ($reason === '') {
            throw AdjustmentRejected::reasonRequired();
        }

        return DB::transaction(function () use ($adjustment, $reason, $actor): ObligationAdjustment {
            // The obligation is locked first, so the sum being corrected is a sum
            // nobody else can move underneath.
            $locked = $this->lock($adjustment->obligation);

            $original = ObligationAdjustment::query()
                ->lockForUpdate()
                ->find($adjustment->getKey());

            if ($original === null) {
                throw AdjustmentRejected::notFound();
            }

            if ($original->isReversal()) {
                throw AdjustmentRejected::cannotReverseAReversal();
            }

            $alreadyReversed = ObligationAdjustment::query()
                ->where('reverses_adjustment_id', $original->id)
                ->exists();

            if ($alreadyReversed) {
                throw AdjustmentRejected::alreadyReversed();
            }

            $adjustmentsTotal = (int) ObligationAdjustment::query()
                ->where('obligation_id', $locked->id)
                ->sum('delta_cop');

            $paid = $this->paidAmount($locked);

            // Withdrawing a correction adds its magnitude back, so the effective
            // amount after the reversal is the one from before the correction. It can
            // only be higher, so it cannot breach either limit; the checks are kept
            // anyway so that a future change to the arithmetic cannot slip past them.
            $effectiveAfter = $locked->base_amount_cop + $adjustmentsTotal - $original->delta_cop;

            if ($effectiveAfter < 0 || $paid > $effectiveAfter) {
                throw AdjustmentRejected::reversalWouldBreachTheBalance($paid, $effectiveAfter);
            }

            $reversal = ObligationAdjustment::query()->create([
                'obligation_id' => $locked->id,
                'type' => AdjustmentType::Reversal->value,
                'delta_cop' => -$original->delta_cop,
                'reason' => $reason,
                'created_by' => $actor->id,
                'reverses_adjustment_id' => $original->id,
            ]);

            event(new ObligationAdjustmentReversed($reversal, $original, $actor));

            return $reversal;
        });
    }

    private function lock(MonthlyObligation $obligation): MonthlyObligation
    {
        $locked = MonthlyObligation::query()
            ->lockForUpdate()
            ->find($obligation->getKey());

        if ($locked === null) {
            throw AdjustmentRejected::obligationNotFound();
        }

        return $locked;
    }

    /**
     * Money already applied to this obligation.
     *
     * Allocations of voided payments do not count: the money did not arrive, so it
     * does not pay anything. The join is what makes that true here rather than in
     * each caller.
     */
    private function paidAmount(MonthlyObligation $obligation): int
    {
        return (int) PaymentAllocation::query()
            ->where('obligation_id', $obligation->id)
            ->whereNull('reversed_at')
            ->whereHas('payment', fn ($query) => $query->whereNull('voided_at'))
            ->sum('amount_cop');
    }
}

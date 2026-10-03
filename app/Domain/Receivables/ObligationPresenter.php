<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

use App\Domain\Billing\ObligationTotals;
use App\Models\MonthlyObligation;
use App\Models\ObligationAdjustment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One place that decides how an obligation reads, so the period screen, the client
 * account and the portfolio cannot show three different numbers for the same debt.
 *
 * Nothing here is stored on the obligation. The amount billed, the adjustments and
 * the payments applied are read and added every time, which is why a voided payment
 * or a reversed allocation changes every balance on the next request without anything
 * being updated in between.
 *
 * `settlement_state` and `is_overdue` are separate because they answer different
 * questions and both can be true. Collapsing them would lose the case that matters
 * most: somebody who paid something and then stopped.
 */
final class ObligationPresenter
{
    /**
     * One obligation, read on its own.
     *
     * @return array<string, mixed>
     */
    public function describe(MonthlyObligation $obligation, ?Carbon $asOf = null): array
    {
        $asOf ??= now();

        $adjustments = (int) ObligationAdjustment::query()
            ->where('obligation_id', $obligation->id)
            ->sum('delta_cop');

        $paid = (int) PaymentAllocation::query()
            ->where('obligation_id', $obligation->id)
            ->whereNull('reversed_at')
            ->whereHas('payment', fn ($query) => $query->whereNull('voided_at'))
            ->sum('amount_cop');

        return $this->shape($obligation, $adjustments, $paid, $asOf);
    }

    /**
     * A page of obligations, in two grouped queries rather than two per row.
     *
     * A month of obligations is read one page at a time. Asking for each one
     * individually would mean three queries per row, and the operator looking at a
     * month with a hundred clients would wait for three hundred.
     *
     * @param  Collection<int, MonthlyObligation>  $obligations
     * @return Collection<int, array<string, mixed>>
     */
    public function describeMany($obligations, ?Carbon $asOf = null): Collection
    {
        $asOf ??= now();

        if ($obligations->isEmpty()) {
            return collect();
        }

        // Loaded here rather than left to the caller: the shapes below all read the
        // period, the client and the company name, so a caller who forgets would get
        // three lazy queries per row with no warning.
        $obligations->loadMissing(['period', 'client', 'company']);

        $ids = $obligations->map(fn (MonthlyObligation $o): int => $o->id)->all();

        $adjustments = ObligationAdjustment::query()
            ->whereIn('obligation_id', $ids)
            ->groupBy('obligation_id')
            ->selectRaw('obligation_id, sum(delta_cop) as total')
            ->pluck('total', 'obligation_id');

        $paid = PaymentAllocation::query()
            ->whereIn('obligation_id', $ids)
            ->whereNull('reversed_at')
            ->whereHas('payment', fn ($query) => $query->whereNull('voided_at'))
            ->groupBy('obligation_id')
            ->selectRaw('obligation_id, sum(amount_cop) as total')
            ->pluck('total', 'obligation_id');

        return $obligations->map(fn (MonthlyObligation $obligation): array => $this->shape(
            $obligation,
            (int) ($adjustments[$obligation->id] ?? 0),
            (int) ($paid[$obligation->id] ?? 0),
            $asOf,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(MonthlyObligation $obligation, int $adjustments, int $paid, Carbon $asOf): array
    {
        $totals = ObligationTotals::for($obligation, $adjustments, $paid);
        $month = $obligation->period?->month();

        return [
            'id' => $obligation->id,
            'period_id' => $obligation->period_id,
            'period_key' => $month?->key(),
            'period_label' => $month?->label(),
            'client_id' => $obligation->client_id,
            'client_name' => $obligation->client?->fullName(),
            'company_id' => $obligation->company_id,
            'company_name' => $obligation->company?->displayName(),
            'base_amount_cop' => $obligation->base_amount_cop,
            'adjustments_cop' => $adjustments,
            'due_on' => $obligation->due_on?->format('Y-m-d'),
            'source' => $obligation->source->value,
            'source_label' => $obligation->source->label(),
            ...$totals->toArray($asOf),
            // Repeated as labels so the interface does not carry a translation table
            // for financial states it has no business owning.
            'settlement_state_label' => $totals->settlementState()->label(),
            'aging_bucket_label' => $totals->agingBucketAsOf($asOf)->label(),
        ];
    }
}

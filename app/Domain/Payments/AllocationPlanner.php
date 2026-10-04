<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\MonthlyObligation;

/**
 * Which debts a payment reaches, and in what order.
 *
 * ## One arithmetic, two callers
 *
 * A03-R1 found the preview and the execution answering the same question with two
 * implementations. The preview iterated obligations in one order and capped each
 * contribution at the remaining payment; the execution did the same but caught a unique
 * violation and **continued**, which meant the plan an operator was shown and the rows
 * that were written could differ — and specifically could differ in the direction that
 * matters most, paying a newer month while an older one stayed unpaid.
 *
 * So the arithmetic lives here, once. `plan()` is called by the preview, which writes
 * nothing, and by the execution, which re-reads under locks and then calls `plan()` again.
 * Both therefore apply the same ordering, the same remaining-balance rule and the same
 * "what is still owed" definition. The execution's second call is what makes the plan
 * authoritative; the preview's first call is what the operator is shown.
 *
 * ## The order is oldest financial period first
 *
 * `period_month`, then `due_on`, then `id`. A payment reconciled against a backlog is a
 * financial record, and "which month was settled first" must not depend on how the
 * database happened to return rows.
 *
 * The sort is by **period month**, not by insertion order and not by due date alone. Two
 * companies owing in the same month are both candidates, and the tie is broken by due date
 * so the result is stable; an obligation with no period would sort first, which is why the
 * key is built defensively rather than with a bare null.
 *
 * ## Repeated application to the same debt is ordinary
 *
 * There is nothing here about one payment reaching one obligation once. The planner treats
 * the remaining balance of a debt as a number to reduce, so paying 80000 of a 200000 debt
 * and later paying the remaining 120000 from the same payment is simply two steps, and the
 * second step finishes a debt the first one started. That is the instalment workflow, and
 * the removed unique index used to make it impossible while reporting oldest-first
 * regardless.
 */
final class AllocationPlanner
{
    /**
     * The debts this payment would reach, in the order it would reach them.
     *
     * @param  list<MonthlyObligation>  $obligations  already filtered to one client, in any order
     * @param  array<int, int>  $remainingByObligation  what is still owed, keyed by obligation id
     * @param  int  $available  what the payment has left to apply
     * @return list<array{obligation: MonthlyObligation, amount_cop: int, remaining_cop: int}>
     */
    public function plan(array $obligations, array $remainingByObligation, int $available): array
    {
        if ($available <= 0 || $obligations === []) {
            return [];
        }

        $ordered = $this->order($obligations);

        $plan = [];
        $left = $available;

        foreach ($ordered as $obligation) {
            if ($left <= 0) {
                break;
            }

            $remaining = $remainingByObligation[$obligation->id] ?? 0;

            // Nothing owed: the debt is settled, or was never a candidate. Not an error and
            // not a reason to stop — the payment may still have something for a later month.
            if ($remaining <= 0) {
                continue;
            }

            $amount = min($remaining, $left);

            $plan[] = [
                'obligation' => $obligation,
                'amount_cop' => $amount,
                'remaining_cop' => $remaining - $amount,
            ];

            $left -= $amount;
        }

        return $plan;
    }

    /**
     * What would be left on the payment afterwards.
     *
     * @param  list<array{obligation: MonthlyObligation, amount_cop: int, remaining_cop: int}>  $plan
     */
    public function unallocatedAfter(array $plan, int $available): int
    {
        return $available - array_sum(array_column($plan, 'amount_cop'));
    }

    /**
     * Oldest period, then soonest due date, then id.
     *
     * A single composite key rather than a list of sort callbacks, so the order is one
     * expression and cannot be reassembled differently by two callers. Zero-padded so the
     * string comparison matches the calendar order, and the id last so two rows with the
     * same period and due date still have one deterministic order.
     *
     * @param  list<MonthlyObligation>  $obligations
     * @return list<MonthlyObligation>
     */
    public function order(array $obligations): array
    {
        $keyed = [];

        foreach ($obligations as $obligation) {
            $keyed[] = [$this->sortKey($obligation), $obligation];
        }

        usort($keyed, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_values(array_map(static fn (array $pair): MonthlyObligation => $pair[1], $keyed));
    }

    /**
     * `@param` the period is read from the loaded relation, so the caller has to eager-load
     * it; a missing relation sorts as an empty month, which puts such a row first and is
     * visible in a test rather than silent.
     */
    private function sortKey(MonthlyObligation $obligation): string
    {
        $period = $obligation->period;

        return sprintf(
            '%s|%s|%010d',
            $period?->period_month->format('Y-m-d') ?? '',
            $obligation->due_on?->format('Y-m-d') ?? '',
            $obligation->id,
        );
    }
}

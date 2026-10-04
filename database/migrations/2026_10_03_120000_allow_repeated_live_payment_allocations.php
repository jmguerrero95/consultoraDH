<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A03-R1: one payment may be applied to one obligation more than once.
 *
 * ## What the index forbade
 *
 *     payment = 300000
 *     obligation = 200000
 *
 *     apply 80000      → refused
 *
 * Refused because of:
 *
 *     CREATE UNIQUE INDEX payment_allocations_live_pair_unique
 *         ON payment_allocations (payment_id, obligation_id)
 *         WHERE reversed_at IS NULL
 *
 * The rationale in the original migration was that two live allocations of one payment
 * to one obligation are ambiguous: is this one allocation or two? The answer is that
 * **both questions are answerable, and only one of them was worth blocking for.** The
 * balance is a sum, so several rows are read as several contributions to one debt, and
 * each row is individually reversible. Meanwhile the workflow it prevented is ordinary:
 * paying a debt in instalments from the same receipt is what a client with 300000 in
 * hand and a 200000 bill does.
 *
 * ## The worse bug it caused
 *
 * The refusal was caught and swallowed. `applyOldestFirst` sorted the debts oldest
 * first, wrote an allocation, hit the index on a debt that already had a partial
 * allocation from this same payment, caught the violation and **continued to the next
 * debt**. So the newest debt could be paid while the oldest stayed partly unpaid, and
 * the operator was told the oldest first ordering had been applied.
 *
 * An append-only ledger has to be able to record what actually happened, including
 * "paid twice against the same debt in two steps".
 *
 * ## What replaces it
 *
 * Nothing at the index level. The conservation guarantees move to row locks, which is
 * where they belong anyway:
 *
 *     total live allocations on a payment     <= payment amount
 *     total live non-voided money on a debt   <= effective obligation amount
 *
 * Both are enforced by `ManagePayments` locking the client, then the payment, then the
 * obligations in ascending id, and recomputing the sums **after** the locks. A unique
 * index cannot express either of those, because both compare an aggregate against a
 * figure on another row.
 *
 * The old index name stays in `SchemaConstraint` as `REMOVED_ALLOCATION_LIVE_PAIR`, so
 * the code that used to translate a violation of it can be found and its absence
 * accounted for, rather than the name silently disappearing.
 *
 * ## Rollback
 *
 * Recreating the index is only safe when no pair carries more than one live row. If
 * legitimate new data does, the rollback **fails loudly and names the pairs** instead
 * of merging or deleting them: two allocation rows are real financial actions and
 * destroying either would be worse than not rolling back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS payment_allocations_live_pair_unique');
    }

    public function down(): void
    {
        // Preflight. Merging two allocation rows into one would erase the fact that
        // two payments happened, so this does not attempt it.
        $conflicts = DB::table('payment_allocations')
            ->whereNull('reversed_at')
            ->groupBy('payment_id', 'obligation_id')
            ->havingRaw('count(*) > 1')
            ->orderBy('payment_id')
            ->orderBy('obligation_id')
            ->get(['payment_id', 'obligation_id']);

        if ($conflicts->isNotEmpty()) {
            $pairs = $conflicts
                ->map(fn ($row): string => sprintf('(%d,%d)', $row->payment_id, $row->obligation_id))
                ->implode(', ');

            throw new RuntimeException(sprintf(
                'A03-R1 no puede restaurar payment_allocations_live_pair_unique: %d par(es) '
                .'tiene(n) más de una asignación viva. No se fusionan ni se borran filas: '
                .'son acciones financieras reales. Revise y revierta las asignaciones '
                .'excedentes a mano. Pares: %s',
                $conflicts->count(),
                $pairs,
            ));
        }

        DB::statement(
            'CREATE UNIQUE INDEX payment_allocations_live_pair_unique '
            .'ON payment_allocations (payment_id, obligation_id) WHERE reversed_at IS NULL'
        );
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A03-R1: which relationship segments produced a monthly obligation.
 *
 * ## The problem this solves
 *
 * `monthly_obligations.client_company_assignment_id` is a single column, and for the
 * ordinary case that is exactly right: one person, one employer, one relationship
 * spanning the month.
 *
 * It stops being enough for a case that is not exotic at all. A client works for
 * Company A until the 10th, leaves, and is rehired by Company A on the 20th. That is
 * two A02 relationship rows, two legal periods of employment, and **one** monthly
 * obligation — because the economic identity is `period + client + company`, not
 * `period + relationship`. Nobody owes twice for one month because they changed
 * departments mid-month.
 *
 * The previous candidate builder treated any second row for the same pair as an
 * "ambiguous relationship state" and refused to bill the month. That made a correct
 * directory entry un-billable.
 *
 * ## Why a table rather than a comma-separated column
 *
 * The choice here is between three shapes, and the alternatives are worse:
 *
 *   * keep the single column and pick the lowest id — this is what the review called
 *     out as unacceptable. The row would claim to come from one relationship while two
 *     produced it, and the second would silently vanish from the explanation.
 *   * store the ids as text in one column — unqueryable, and a financial audit trail
 *     that cannot answer "which relationships does this obligation rest on" with SQL.
 *   * make the column nullable and rely on absence — loses the ordinary case, which is
 *     the overwhelming majority, for the sake of a rare one.
 *
 * A mapping table keeps both: the single column stays as the primary source for the
 * ordinary case, and the mapping table records **every** source segment. Both are
 * written, and the mapping table is the one to trust when they are asked to disagree.
 *
 * ## Nothing is invented during the migration
 *
 * Existing obligations get exactly one provenance row each, derived from the column
 * they already carry. A row whose `client_company_assignment_id` is NULL gets no
 * provenance row, because there was no reference to record and inventing one would
 * attach a real employment record to a debt that may not be related to it. Those rows
 * are reported rather than guessed at.
 *
 * `down()` drops the table. It merges nothing: the single column is still there and
 * still holds the primary source, so a rollback loses the extra detail and no
 * information that existed before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obligation_source_assignments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('obligation_id')
                ->constrained('monthly_obligations')
                ->restrictOnDelete();

            // The A02 relationship segment. RESTRICT: a relationship is history, and a
            // provenance row pointing at a deleted relationship would leave an
            // obligation that cannot be explained.
            $table->foreignId('client_company_assignment_id')
                ->constrained('client_company_assignments')
                ->restrictOnDelete();

            $table->timestamps();

            // The same segment cannot be recorded twice for one obligation. This is a
            // bookkeeping identity, not a financial one: it stops a retry from
            // duplicating provenance.
            $table->unique(
                ['obligation_id', 'client_company_assignment_id'],
                'obligation_source_assignments_pair_unique'
            );

            // "Explain this obligation" reads in one direction.
            $table->index(
                ['client_company_assignment_id'],
                'obligation_source_assignments_assignment_index'
            );
        });

        // Backfill from the column that already exists. One row per obligation that
        // names a relationship, and only that.
        $backfilled = DB::table('monthly_obligations')
            ->whereNotNull('client_company_assignment_id')
            ->orderBy('id')
            ->select('id', 'client_company_assignment_id')
            ->get();

        $now = now();

        foreach ($backfilled as $obligation) {
            $exists = DB::table('obligation_source_assignments')
                ->where('obligation_id', $obligation->id)
                ->where('client_company_assignment_id', $obligation->client_company_assignment_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('obligation_source_assignments')->insert([
                'obligation_id' => $obligation->id,
                'client_company_assignment_id' => $obligation->client_company_assignment_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Reported rather than repaired. An obligation with no relationship reference
        // cannot be given one here: the correct segment is not derivable from anything
        // this migration can see, and guessing would fabricate provenance for a debt.
        $orphans = DB::table('monthly_obligations')
            ->whereNull('client_company_assignment_id')
            ->orderBy('id')
            ->pluck('id');

        if ($orphans->isNotEmpty()) {
            logger()->warning(
                'A03-R1 provenance: obligations exist with no relationship reference, '
                .'so no provenance row was created for them.',
                ['obligation_ids' => $orphans->all()],
            );
        }
    }

    public function down(): void
    {
        // No data is destroyed or merged here. `monthly_obligations` still carries
        // `client_company_assignment_id` with the primary source, exactly as it did
        // before this migration, so rolling back loses the multi-segment detail and
        // nothing else.
        Schema::dropIfExists('obligation_source_assignments');
    }
};

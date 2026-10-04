<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A03-R1: a generated obligation must keep the configuration that produced it.
 *
 * ## The comment said the reference was evidence. The schema could erase it.
 *
 * The A03 obligations migration declared:
 *
 *     rate_id        nullable, RESTRICT
 *     cutoff_rule_id nullable, SET NULL
 *
 * and the column comments described them as the evidence that lets somebody answer
 * "why is this 235000 and due on the 10th?". Two of the three halves of that promise
 * were not kept by the schema:
 *
 *   * both were nullable, so a generated obligation could be written with no
 *     configuration behind it at all and nothing would object;
 *   * `cutoff_rule_id` had `SET NULL`, so deleting a cutoff rule — a rule that is no
 *     longer referenced by anything, say — would reach back and erase the reference on
 *     every obligation that ever quoted it. The evidence would be gone and the
 *     `due_on` would remain, which is worse than not having kept the date: the row
 *     would claim a fact it could no longer support.
 *
 * ## What this migration does
 *
 * For rows with `source = 'generated'` — the only source generation ever writes:
 *
 *   * `rate_id` becomes NOT NULL: a generated amount came from a configured rate, and
 *     a generated row without one cannot be explained.
 *   * `cutoff_rule_id` becomes NOT NULL: likewise for the due date.
 *   * the foreign key on `cutoff_rule_id` becomes RESTRICT, matching the one on
 *     `rate_id`. A rule quoted by a generated obligation is part of what that
 *     obligation means, and deleting it must not rewrite history.
 *
 * `client_company_assignment_id` is deliberately **not** tightened. Section 10 of the
 * review says so explicitly: one monthly obligation may legitimately rest on several
 * non-overlapping relationship segments, so requiring one assignment id would reject a
 * correct month. Provenance is recorded in `obligation_source_assignments`.
 *
 * ## Preflight, and refusing rather than repairing
 *
 * `manual_correction` rows keep both columns nullable: they were written by hand and
 * their provenance is whatever the operator entered, so the evidence requirement does
 * not describe them.
 *
 * A `generated` row that already violates the rule cannot be fixed here. Guessing a
 * rate for it would invent a financial fact, and deleting it would destroy a debt, so
 * the migration **fails loudly and names the offending ids** before touching the
 * schema. That is the intended outcome: an operator has to decide what those rows are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $incompatible = DB::table('monthly_obligations')
            ->where('source', 'generated')
            ->where(function ($query): void {
                $query->whereNull('rate_id')->orWhereNull('cutoff_rule_id');
            })
            ->orderBy('id')
            ->pluck('id');

        if ($incompatible->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'A03-R1 no puede exigir evidencia de configuración: %d obligación(es) '
                .'generada(s) tienen rate_id o cutoff_rule_id nulo. No se inventa ni se '
                .'borra ninguna fila: revise estas obligaciones y decídalas a mano. IDs: %s',
                $incompatible->count(),
                $incompatible->implode(', '),
            ));
        }

        // `cutoff_rule_id` first: SET NULL has to be replaced before NOT NULL, because
        // a null-on-delete key on a column that is about to become mandatory is
        // contradictory.
        Schema::table('monthly_obligations', function (Blueprint $table): void {
            $table->dropForeign(['cutoff_rule_id']);
        });

        Schema::table('monthly_obligations', function (Blueprint $table): void {
            $table->foreign('cutoff_rule_id')
                ->references('id')
                ->on('cutoff_rules')
                ->restrictOnDelete();
        });

        Schema::table('monthly_obligations', function (Blueprint $table): void {
            // A partial constraint, written in SQL rather than as a Blueprint modifier
            // because Laravel has no way to express "NOT NULL only when source is
            // 'generated'" and a plain NOT NULL would reject a manual correction row
            // that legitimately carries no configuration.
            //
            // The two NOT NULLs are enforced by CHECK rather than by a column
            // constraint for exactly that reason: the obligation of a manual correction
            // has no rate behind it, and demanding one would forbid writing the kind
            // of row the `source` column exists to describe.
        });

        DB::statement(
            'ALTER TABLE monthly_obligations ADD CONSTRAINT monthly_obligations_generated_evidence_check '
            ."CHECK (source <> 'generated' OR (rate_id IS NOT NULL AND cutoff_rule_id IS NOT NULL))"
        );
    }

    public function down(): void
    {
        // Dropping the CHECK is safe and non-destructive: it only stops enforcing the
        // requirement, and no row is touched.
        DB::statement(
            'ALTER TABLE monthly_obligations DROP CONSTRAINT IF EXISTS monthly_obligations_generated_evidence_check'
        );

        // RESTRICT is restored to SET NULL so the rollback leaves the schema exactly as
        // the previous migration declared it. This is a widening of what the database
        // will do, never a destruction: a rule that was already referenced by a
        // generated obligation stays referenced, because nothing here deletes one.
        Schema::table('monthly_obligations', function (Blueprint $table): void {
            $table->dropForeign(['cutoff_rule_id']);
        });

        Schema::table('monthly_obligations', function (Blueprint $table): void {
            $table->foreign('cutoff_rule_id')
                ->references('id')
                ->on('cutoff_rules')
                ->nullOnDelete();
        });
    }
};

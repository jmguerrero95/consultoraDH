<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A03: monthly obligations and the adjustments that amend them.
 *
 * ## An obligation is a snapshot, not a view
 *
 * The row stores what the system decided at generation time: the base amount, the
 * due date, and the identifiers of the rate and the cutoff rule that produced them.
 * Later changes to any of those inputs do **not** rewrite the row.
 *
 * That is the single most important property of this table. A generated obligation
 * that recomputed itself from current configuration would mean that correcting a
 * rate in November silently changed what the system said about March, and nobody
 * could afterwards answer "what did we bill?" for any month other than the last
 * one edited. Corrections are separate, recorded operations with their own audit
 * trail: `obligation_adjustments`.
 *
 * ## One obligation per client and company per month
 *
 * Unique on `(period_id, client_id, company_id)`. A client working for two
 * *different* companies in one month has two obligations, which is correct and
 * intended. A client with two open relationships to the *same* company is refused
 * by A02's domain, and refused here too.
 *
 * ## Identity is referenced, never copied
 *
 * `client_id` and `company_id` are foreign keys. A person's name is not copied into
 * the obligation, because A02's history already records who was associated with
 * whom and when, and two copies of the same fact drift apart. What the obligation
 * adds is the money and the dates, which is what no other table holds.
 *
 * ## Amounts are whole pesos
 *
 * BIGINT. See the note on `client_company_rates.amount_cop`: this system has never
 * needed centavos and a type that implies them is a place for rounding differences
 * to hide.
 *
 * ## No hard deletes
 *
 * There is no `deleted_at` and no delete path. A structural change is made by
 * closing a period, never by removing the obligations inside it, and the foreign
 * keys RESTRICT so a client or company referenced by money cannot disappear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_obligations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('period_id')->constrained('monthly_periods')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            // Which A02 relationship produced this obligation. Nullable because the
            // relationship is history: it is closed when the employment ends, and an
            // obligation for March must not be affected by that closing. Restricting
            // the delete means the link can be kept as evidence without being a reason
            // to keep a row alive forever.
            $table->foreignId('client_company_assignment_id')
                ->nullable()
                ->constrained('client_company_assignments')
                ->nullOnDelete();

            // The configuration that produced this row. Kept so the snapshot can be
            // explained years later: "why is this 235000 and due on the 10th?"
            $table->foreignId('rate_id')->nullable()->constrained('client_company_rates')->restrictOnDelete();
            $table->foreignId('cutoff_rule_id')->nullable()->constrained('cutoff_rules')->nullOnDelete();

            // Whole Colombian pesos.
            $table->bigInteger('base_amount_cop');

            // The resolved cutoff date, already clamped to a real calendar day.
            $table->date('due_on');

            $table->timestamp('generated_at')->useCurrent();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('source', 30)->default('generated');

            $table->timestamps();

        });

        DB::statement(
            'ALTER TABLE monthly_obligations ADD CONSTRAINT monthly_obligations_amount_positive_check '
            .'CHECK (base_amount_cop > 0)'
        );

        DB::statement(
            'ALTER TABLE monthly_obligations ADD CONSTRAINT monthly_obligations_due_on_check '
            .'CHECK (due_on IS NOT NULL)'
        );

        DB::statement(
            'ALTER TABLE monthly_obligations ADD CONSTRAINT monthly_obligations_source_check '
            ."CHECK (source in ('generated', 'manual_correction'))"
        );

        // One economic obligation for one client and one company in one month.
        Schema::table('monthly_obligations', function (Blueprint $table): void {
            $table->unique(
                ['period_id', 'client_id', 'company_id'],
                'monthly_obligations_period_client_company_unique'
            );

            // Cartera filters by company and by outstanding state, and the client
            // account reads one client's obligations newest first. These are the three
            // reads the receivables screen performs; none of them may scan.
            $table->index(['company_id', 'due_on'], 'monthly_obligations_company_due_index');
            $table->index(['client_id', 'period_id'], 'monthly_obligations_client_period_index');
            $table->index(['period_id'], 'monthly_obligations_period_index');
        });

        Schema::create('obligation_adjustments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('obligation_id')->constrained('monthly_obligations')->restrictOnDelete();

            $table->string('type', 20);

            // Signed. Positive increases what is owed, negative reduces it. The sign
            // is in the column rather than in the type, because a discount and a
            // surcharge are the same operation with different directions and the
            // arithmetic should not have to know which is which.
            $table->bigInteger('delta_cop');

            $table->text('reason');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A reversal points at the adjustment it undoes. Self-referencing, so the
            // database can refuse a reversal of something that is not an adjustment.
            $table->foreignId('reverses_adjustment_id')
                ->nullable()
                ->unique()
                ->constrained('obligation_adjustments')
                ->restrictOnDelete();

        });

        DB::statement(
            'ALTER TABLE obligation_adjustments ADD CONSTRAINT obligation_adjustments_type_check '
            ."CHECK (type in ('discount', 'surcharge', 'correction', 'credit', 'reversal'))"
        );

        /*
         | A zero adjustment is a comment, not an adjustment. Storing it would put a
         | row in the ledger that changes nothing and still has to be interpreted by
         | whoever reads the balance.
         */
        DB::statement(
            'ALTER TABLE obligation_adjustments ADD CONSTRAINT obligation_adjustments_delta_nonzero_check '
            .'CHECK (delta_cop <> 0)'
        );

        /*
         | A reversal is not optional about what it undoes, and an ordinary
         | adjustment is not allowed to claim that it reverses something. Without
         | this, a `discount` could name an adjustment to undo and be counted twice:
         | once for itself and once because something claimed it undid it.
         */
        DB::statement(
            'ALTER TABLE obligation_adjustments ADD CONSTRAINT obligation_adjustments_reversal_shape_check '
            ."CHECK ((type = 'reversal') = (reverses_adjustment_id IS NOT NULL))"
        );

        Schema::table('obligation_adjustments', function (Blueprint $table): void {
            // "Every active adjustment of this obligation", which is what the balance
            // formula reads.
            $table->index(['obligation_id'], 'obligation_adjustments_obligation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obligation_adjustments');
        Schema::dropIfExists('monthly_obligations');
    }
};

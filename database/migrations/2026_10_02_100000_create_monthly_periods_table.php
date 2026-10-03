<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A03: the monthly periods that obligations are generated into.
 *
 * ## A period is a row, not a derived thing
 *
 * The month is identified by its first day, `2026-10-01`, because that is what
 * makes it comparable with the A02 interval columns without conversion. There is
 * no `2026-13` and no ambiguous `2026-1`: the check below refuses anything that is
 * not a real first day of a month, so a period cannot exist in a shape the rest of
 * the system would have to guess about.
 *
 * ## Periods are created, never implied
 *
 * The calendar advancing does not open October. A period exists because somebody
 * created it, which means "which months are we billing" is a decision the business
 * makes and the system records, rather than an accident of the date. There is no
 * `first of the month` job, and no row appears that nobody asked for.
 *
 * ## Several periods may be open at once
 *
 * This is deliberate. An earlier month can legitimately stay open while it is
 * being corrected while a newer month is already being billed, and refusing that
 * would force the operator to close a month they are not finished with in order to
 * open one they are. `MonthlyPeriodResolver` answers "which period is current"
 * with a documented order instead of pretending there can only be one.
 *
 * ## Closed means frozen structure, not frozen money
 *
 * A closed period refuses generation and regeneration. It does not refuse payments:
 * a debt from March is paid in July, and closing March must not make that debt
 * unpayable. The state therefore records who closed it and when, because the
 * question "was this month settled before or after the fact" is the first question
 * anybody asks about a corrected number.
 *
 * Foreign keys RESTRICT. A period that obligations point at is never removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_periods', function (Blueprint $table): void {
            $table->id();

            // The first day of the month. Unique, so a month cannot be created twice.
            $table->date('period_month')->unique();

            $table->string('status', 20)->default('open');

            $table->timestamp('opened_at')->useCurrent();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();

            // Both are written together on close and on reopen, so the database
            // refuses one without the other rather than leaving a period that
            // appears closed with nobody responsible for closing it.
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();

            // Reopening is exceptional, so the reason is kept after the period is
            // closed again. Losing it would make a corrected month indistinguishable
            // from one that was closed correctly the first time.
            $table->text('last_reopen_reason')->nullable();

            // Whether generation has ever been run for this month, independently of
            // whether it produced anything.
            //
            // An empty month and an untouched month both have zero obligations, and
            // they are opposite situations: one means "nobody was billed", the other
            // means "nobody has run generation". Refusing to close an untouched month
            // is right; refusing to close a month in which nobody was billed would
            // leave the system unable to settle a genuinely empty portfolio forever.
            // The absence of this timestamp is how those two are told apart.
            $table->timestamp('generation_performed_at')->nullable();
            $table->foreignId('generation_performed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

        });

        /*
         | Only a real first day of a month may be stored.
         |
         | Without this, `2026-10-17` could become a period and every later comparison
         | against a relationship interval would be subtly wrong rather than loudly
         | broken.
         */
        DB::statement(
            'ALTER TABLE monthly_periods ADD CONSTRAINT monthly_periods_first_day_check '
            .'CHECK (extract(day from period_month) = 1)'
        );

        DB::statement(
            'ALTER TABLE monthly_periods ADD CONSTRAINT monthly_periods_status_check '
            ."CHECK (status in ('open', 'closed'))"
        );

        /*
         | A closed period says when it was closed; an open one does not claim to have
         | been closed at all. A period that is closed with no timestamp cannot be
         | reported on, and one that is open while carrying a closure date is a
         | contradiction rather than a state.
         */
        DB::statement(
            'ALTER TABLE monthly_periods ADD CONSTRAINT monthly_periods_closed_consistency_check '
            ."CHECK ((status = 'closed' AND closed_at IS NOT NULL) "
            ."OR (status = 'open' AND closed_at IS NULL))"
        );

        // Listing the periods of a portfolio is by month, almost always descending
        // and sometimes within a range. The unique index already covers the key, so
        // this is for the range scans `period_from`/`period_to` produce.
        Schema::table('monthly_periods', function (Blueprint $table): void {
            $table->index(['status', 'period_month'], 'monthly_periods_status_month_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_periods');
    }
};

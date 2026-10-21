<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A05-REP-02/04 — the idempotency key of a scheduled report was never stored.
 *
 * `generated_reports.occurrence_key` existed with a plain index, and
 * `ReportGenerator::generate()` passed it to `create()` — but the attribute was
 * not in the model's fillable list, and mass-assignment protection dropped it
 * without a word. Every scheduled report was therefore stored with a null key.
 *
 * The consequence was not theoretical: the runner's "does the artifact of this
 * occurrence already exist?" lookup never found anything, so **every retry of
 * an interrupted occurrence produced a second report** of the same occurrence,
 * and the index guarded a value that was never written.
 *
 * The index becomes unique, so the guarantee is the database's rather than the
 * runner's: two workers that race for the same occurrence cannot both write
 * the artifact, and the loser reads the winner's row.
 *
 * The column is nullable, and a manual report has no occurrence, so the
 * ordinary rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nothing to convert: the key was never written, so there is no
        // duplicate to resolve. The check is here so that a deployment that
        // somehow has duplicates says so instead of failing with a constraint
        // violation nobody can read.
        $duplicates = DB::table('generated_reports')
            ->select('occurrence_key')
            ->whereNotNull('occurrence_key')
            ->groupBy('occurrence_key')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'There are %d duplicated occurrence_key values in generated_reports. '
                .'Resolve them before the unique index can be created.',
                $duplicates->count(),
            ));
        }

        Schema::table('generated_reports', function (Blueprint $table): void {
            $table->dropIndex('generated_reports_occurrence_index');
        });

        Schema::table('generated_reports', function (Blueprint $table): void {
            $table->unique('occurrence_key', 'generated_reports_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('generated_reports', function (Blueprint $table): void {
            $table->dropUnique('generated_reports_occurrence_unique');
        });

        Schema::table('generated_reports', function (Blueprint $table): void {
            $table->index(['occurrence_key'], 'generated_reports_occurrence_index');
        });
    }
};

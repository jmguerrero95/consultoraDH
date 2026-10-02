<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A02: the historical relationship between a client and a company.
 *
 * Rows are never edited to change which company they point at, and never
 * deleted. A transfer closes the open row and opens a new one, so "which company
 * was this person in during 2024" is answerable from the rows themselves rather
 * than from a log that might be missing.
 *
 * A relationship is active exactly when `ended_on IS NULL`. That single
 * convention is what makes "close on a date" mean the same thing everywhere.
 *
 * ## The meaning of the two dates
 *
 * The period is `[started_on, ended_on)`. `started_on` is the first day the
 * relationship is effective; `ended_on` is the first day it is **no longer**
 * effective. A transfer writes the same effective date to both rows: the old one
 * as its end, the new one as its start, so the day before belongs to the old
 * company, the effective day belongs to the new one, and no day belongs to both
 * or to neither.
 *
 * Stated here because A03 reports on periods and has to inherit this rather than
 * invent its own arithmetic; the rule itself lives in
 * `App\Domain\Shared\EffectivePeriod` and the `activeOn()` scope implements it.
 *
 * Foreign keys RESTRICT rather than CASCADE. A client cannot be deleted while a
 * relationship row references it, and that is deliberate: the rows are the
 * historical record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_company_assignments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            $table->date('started_on');
            $table->date('ended_on')->nullable();

            // CARGO belongs to the relationship, not to the person: the same
            // client is an analyst in one company and a manager in the next.
            $table->string('job_title', 120)->nullable();
            $table->text('notes')->nullable();

            /*
             | Explicit authorisation of a second open relationship.
             |
             | The source data contains people in more than one company at the
             | same time. Some of that is a real overlap and some of it is an
             | error, and the application cannot tell them apart, so a parallel
             | relationship has to be authorised by a person with a written
             | reason. Its presence is what later data quality checks read to
             | decide the overlap was intentional.
             |
             | These are real columns rather than a JSON blob so they can be
             | queried and constrained, not buried in metadata.
             */
            $table->timestampTz('parallel_authorized_at')->nullable();
            $table->text('parallel_reason')->nullable();

            $table->timestamps();

            // "Which companies does this client currently work for?"
            $table->index(['client_id', 'ended_on'], 'assignments_client_active_index');

            // "How many active workers does this company have?"
            $table->index(['company_id', 'ended_on'], 'assignments_company_active_index');

            // "Who worked here during a period?" for the historical screens.
            $table->index(['client_id', 'started_on'], 'assignments_client_period_index');
        });

        /*
         | The date ordering, enforced by the database.
         |
         | A relationship that ends before it starts is not a rare typo: it is
         | what a mistyped year produces, and it would quietly corrupt every
         | period report.
         */
        DB::statement(
            'ALTER TABLE client_company_assignments ADD CONSTRAINT assignments_dates_check '
            .'CHECK (ended_on IS NULL OR ended_on >= started_on)'
        );

        /*
         | A parallel relationship must say why.
         |
         | Both columns together, so neither a bare timestamp nor a bare
         | sentence can be recorded.
         */
        DB::statement(
            'ALTER TABLE client_company_assignments ADD CONSTRAINT assignments_parallel_check '
            .'CHECK ((parallel_authorized_at IS NULL AND parallel_reason IS NULL) '
            .'OR (parallel_authorized_at IS NOT NULL AND btrim(parallel_reason) IS NOT NULL AND length(btrim(parallel_reason)) > 0))'
        );

        DB::statement(
            'ALTER TABLE client_company_assignments ADD CONSTRAINT assignments_job_title_check '
            .'CHECK (job_title IS NULL OR length(btrim(job_title)) > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('client_company_assignments');
    }
};

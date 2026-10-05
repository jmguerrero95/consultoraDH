<?php

declare(strict_types=1);

use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Imports\LegacyIssueSeverity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A04: the questions a person has to answer before anything is written.
 *
 * ## This is the work, not a log
 *
 * 17 affiliation dates, 56 rows without an amount, two duplicated documents, a company
 * that appears under two almost-identical NITs, 23 cells carrying something that looks like
 * a password. None of those can be decided by a rule without risking somebody's identity,
 * their employer's record, or a number that decides what they are billed.
 *
 * So they become rows here, and `apply` is refused while any blocking one is open. That is
 * the mechanism the whole module turns on: the importer's job is to *find* and to refuse
 * to guess, not to be clever.
 *
 * ## `context` is sanitised at write time
 *
 * It carries the sheet, the row number, the cell and the token that was recognised — never
 * the credential, never the full line of a person. `credential_like_content` in particular
 * names the pattern and the position and nothing else, because the interface shows this
 * column and §4.3 requires that the secret never reaches it.
 *
 * ## One row per finding, not per finding per row
 *
 * `row_id` is nullable for the batch-level codes — an unreadable company title raises one
 * issue for the block rather than one per person under it. A person answering "this NIT is
 * wrong" wants one dialog and one answer; 24 identical dialogs is how a review takes a day
 * and gets abandoned halfway.
 *
 * ## A resolution is a row update, not a deletion
 *
 * `resolved_by`, `resolved_at` and `resolution` keep what was decided and who decided it.
 * The apply needs to read that decision, and so does the next person who opens the batch:
 * an import whose issues vanish when resolved has no memory of having had any.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_import_issues', function ($table): void {
            $table->id();

            $table->foreignId('legacy_import_id')
                ->constrained('legacy_imports')
                ->cascadeOnDelete();

            /** NULL for the codes that are about the workbook rather than a person. */
            $table->foreignId('row_id')
                ->nullable()
                ->constrained('legacy_import_rows')
                ->cascadeOnDelete();

            $table->string('code', 64);

            $table->string('severity', 12)->default(LegacyIssueSeverity::Error->value);

            /**
             * Whether `apply` is refused while this is open.
             *
             * A column rather than a lookup of the code, because the reviewer can narrow an
             * issue: "this one does not block, I checked" is a legitimate thing to record and
             * the apply must then honour it. The default is the code's own opinion, so the
             * column is only written when a person disagrees.
             */
            $table->boolean('blocking')->default(true);

            /** Which part of the row the issue is about, for the dialog to focus it. */
            $table->string('field', 60)->nullable();

            /** For a person to read. Never carries a secret. */
            $table->text('message');

            /** Sheet, row, cell, recognised token. Sanitised before it is written. */
            $table->jsonb('context')->nullable();

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();

            /** What was decided. Sanitised, and never re-read as if it were source data. */
            $table->jsonb('resolution')->nullable();

            $table->timestampsTz();

            $table->index(['legacy_import_id', 'code']);
            $table->index(['legacy_import_id', 'severity']);
            $table->index(['legacy_import_id', 'resolved_at']);
        });

        DB::statement('ALTER TABLE legacy_import_issues ADD CONSTRAINT legacy_import_issues_code_check
            CHECK (code IN ('.
            implode(',', array_map(
                static fn (LegacyImportIssue $issue): string => "'{$issue->value}'",
                LegacyImportIssue::cases(),
            )).'))');

        DB::statement('ALTER TABLE legacy_import_issues ADD CONSTRAINT legacy_import_issues_severity_check
            CHECK (severity IN ('.
            implode(',', array_map(
                static fn (LegacyIssueSeverity $severity): string => "'{$severity->value}'",
                LegacyIssueSeverity::cases(),
            )).'))');

        // A resolution is either complete or absent. Half of one — a timestamp with no
        // person, or a decision with no timestamp — is how "resolved" becomes a claim
        // nobody can audit.
        DB::statement('ALTER TABLE legacy_import_issues ADD CONSTRAINT legacy_import_issues_resolution_check
            CHECK ((resolved_at IS NULL AND resolved_by IS NULL AND resolution IS NULL)
                OR (resolved_at IS NOT NULL AND resolution IS NOT NULL))');

        // §5.3: `row_id` is nullable, because some issues are about the workbook rather than
        // about a person line — a sheet that is not a month, a block with no title, a company
        // identity conflict.
        //
        // There is deliberately **no** constraint tying `row_id` to a list of codes. An earlier
        // version had one, naming the codes allowed to be row-less, and it rejected the two
        // issues this module raises most: `invalid_affiliation_date` and
        // `duplicate_conflicting_row` both carry a sheet and a row *number* in their context
        // rather than a foreign key, because the number is where the reviewer has to look and a
        // join to a staged row that may later be rebuilt would move the reference out from
        // under them. Enumerating codes in a CHECK means every new code has to be remembered
        // in two places, and forgetting it turns a question into a 500.
        //
        // What is enforced instead is the other half: an issue that points at a row of *this*
        // import must point at a row that exists, which the foreign key does.
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_issues');
    }
};

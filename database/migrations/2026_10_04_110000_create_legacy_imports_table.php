<?php

declare(strict_types=1);

use App\Domain\Imports\ImportProfile;
use App\Domain\Imports\LegacyImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A04: the import itself — one row per file the operator uploaded.
 *
 * ## Why the file is a row and not a column somewhere
 *
 * An import is an object with its own life: it is uploaded, parsed, reviewed, planned,
 * applied, and it can fail at any point. Its state has to be queryable ("what is in
 * review?"), and it has to be the thing a job locks, because two clicks on `apply` must
 * not produce two applies. All of that is a table.
 *
 * ## The status is CHECKed, not just typed
 *
 * `LegacyImportStatus` is a PHP enum and the column is a `CHECK`, so a console command or a
 * hand-written query cannot put a row into a state no code knows how to read. That matters
 * here more than in most tables because the state *is* the authorisation: `apply` is
 * refused unless the status is `ready`, and an unknown status would have to be treated as
 * either "refuse" or "allow", and the safe default depends on the code path.
 *
 * ## `summary` holds counts and nothing else
 *
 * Sheet count, block count, row count, how many companies and clients were recognised,
 * how many issues by severity. Aggregate numbers about a parse, never cell contents:
 * §4.3 requires that a database representation of the workbook be redacted, and a JSON
 * column with counts in it is trivially checkable, whereas a JSON column with names in it
 * is a place a future change will put them.
 *
 * ## The failure columns are for a message a person may read
 *
 * `failure_message` is sanitised at write time by the caller. It is text that reaches an
 * operator through the interface, so it must never carry a path from a stranger's disk, a
 * fragment of a credential, or a stack trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_imports', function ($table): void {
            $table->id();

            /**
             * The public identifier.
             *
             * A UUID and not the autoincrement id, because this value appears in
             * `audit_events.metadata` and in job payloads, and a sequential id there would
             * let anybody infer how many imports the system has processed. It is also the
             * directory name under `storage/app/private/imports`, so the upload path cannot
             * be guessed from the filename the operator chose.
             */
            $table->uuid('uuid')->unique();

            $table->string('profile', 48);

            /**
             * The name the operator's browser sent.
             *
             * Kept as metadata for the review screen and never used to build a path. A
             * filename is attacker-controlled and may contain `../`, a NUL, or 300
             * characters of someone's own naming habits.
             */
            $table->string('original_filename', 255);

            /** Relative to the private disk's `imports` root. Never a public path. */
            $table->string('stored_path', 255);

            /**
             * SHA-256 of the bytes as received.
             *
             * §12.1: this is what makes a second application of the same file detectable
             * without comparing content, and what lets the interface point at the previous
             * import instead of silently writing everything again.
             */
            $table->char('sha256', 64);

            $table->unsignedBigInteger('file_size');

            $table->string('status', 20)->default(LegacyImportStatus::Uploaded->value);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('parse_started_at')->nullable();
            $table->timestampTz('parsed_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('failed_at')->nullable();

            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();

            /**
             * How many attempts the parse and the apply have made.
             *
             * A retry-safe job must be able to say it already ran, and a counter is cheaper
             * than inferring that from timestamps.
             */
            $table->unsignedSmallInteger('parse_attempts')->default(0);
            $table->unsignedSmallInteger('apply_attempts')->default(0);

            /** Counts only. See the class docblock. */
            $table->jsonb('summary')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index('created_by');
        });

        // The profile and the status are both closed sets, and both are authorisation in
        // disguise: an unknown profile means no reader, and an unknown status means `apply`
        // cannot decide whether it may run.
        DB::statement('ALTER TABLE legacy_imports ADD CONSTRAINT legacy_imports_profile_check
            CHECK (profile = \''.ImportProfile::BlindenLegacyMonthlyV1->value.'\')');

        DB::statement('ALTER TABLE legacy_imports ADD CONSTRAINT legacy_imports_status_check
            CHECK (status IN ('.
            implode(',', array_map(
                static fn (LegacyImportStatus $status): string => "'{$status->value}'",
                LegacyImportStatus::cases(),
            )).'))');

        DB::statement('ALTER TABLE legacy_imports ADD CONSTRAINT legacy_imports_applied_shape_check
            CHECK ((status = \'applied\') = (applied_at IS NOT NULL))');

        // A positive size, and small enough that a number typed by hand is not accepted as
        // an upload. `config/imports.php` carries the operational limit; this is the floor
        // that keeps a zero-byte or negative row out of the table.
        DB::statement('ALTER TABLE legacy_imports ADD CONSTRAINT legacy_imports_file_size_check
            CHECK (file_size > 0)');

        // The same file may be uploaded again to be *compared*, but only one application
        // of a given hash may exist. Partial, because two imports of the same file may sit
        // in review at the same time without either being applied.
        // Partial, because two imports of the same file may sit in review at the same time
        // without either being applied. Written as SQL for the same reason the A03 indexes
        // are: `Schema` has no partial-index builder.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX legacy_imports_sha256_applied_unique
                ON legacy_imports (sha256)
                WHERE status = 'applied'
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_imports');
    }
};

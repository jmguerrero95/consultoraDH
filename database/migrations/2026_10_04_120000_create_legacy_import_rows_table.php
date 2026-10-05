<?php

declare(strict_types=1);

use App\Domain\Imports\ImportRowState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A04: one row per person-line in the source workbook.
 *
 * ## What is stored and what is not
 *
 * The whole original file stays on the private disk as the raw evidence, so this table
 * does **not** copy every original cell. That would be a second copy of production personal
 * data in the database, with none of the protection the file has. What it stores is what
 * the *domain* will need to decide anything:
 *
 *   - which sheet and row it came from, so a plan action can point a person at a line;
 *   - the normalised company and client identity it proposes;
 *   - the affiliation date, raw **redacted** and parsed separately;
 *   - the monthly amount, the EPS/AFP/CCF/ARL tokens, and the risk/job candidates;
 *   - a fingerprint of the normalised row, which is what makes duplicate detection a
 *     comparison rather than a judgement.
 *
 * ## `novelty` and the free-text columns are redacted, not truncated
 *
 * §4.3. The real workbook carries credentials inside free-text cells — 23 of them. The
 * redactor rewrites the value (`CLAVE: [REDACTED]`) rather than dropping the cell, because
 * the text around a credential is operational evidence: "se.shared por CLAVE: hola123"
 * still tells the reviewer the sheet is a shared one.
 *
 * ## The fingerprint is over the *normalised* row
 *
 * `duplicate_exact_row` and `duplicate_conflicting_row` are different problems with the
 * same key. The only way to tell them apart without guessing is to compare what the two
 * rows *mean*: two lines that normalise to the same bytes are the same fact written twice,
 * and two that share a natural key but differ in meaning are a contradiction that needs a
 * person. Fingerprinting the raw cells would classify reformatting as a conflict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_import_rows', function ($table): void {
            $table->id();

            $table->foreignId('legacy_import_id')
                ->constrained('legacy_imports')
                ->cascadeOnDelete();

            $table->string('sheet_name', 64);

            /** First day of the month the sheet is about. Drives every date decision. */
            $table->date('sheet_month');

            /** The row number in the sheet, 1-based, as a person would count it. */
            $table->unsignedInteger('source_row_number');

            /** Index of the company block within the sheet, 0-based. */
            $table->unsignedSmallInteger('block_index')->default(0);

            /**
             * `NIT-NOMBRE`, the identity of the block as read.
             *
             * The name is kept because it is the evidence a person needs to recognise which
             * company a conflict is about, and it is not a person: a company title is a
             * business name that the operator already has authority to read.
             */
            $table->string('company_block_key', 255);

            /** Normalised NIT base, or NULL when it could not be read. */
            $table->string('company_tax_id', 32)->nullable();

            $table->string('company_display_name', 180)->nullable();

            /** `CC:12345678` style key, or NULL when the document could not be read. */
            $table->string('client_identity_key', 40)->nullable();

            $table->string('document_type', 16)->nullable();
            $table->string('document_number', 40)->nullable();

            $table->string('first_names', 120)->nullable();
            $table->string('last_names', 120)->nullable();

            /** Address, phone and email. Empty source never clears an existing value. */
            $table->string('address', 180)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 180)->nullable();

            /**
             * `FECHA AFILIACION` as written, redacted.
             *
             * Kept verbatim because it is the evidence for `invalid_affiliation_date`: a
             * person resolving that issue needs to see what the cell actually said, and the
             * value is a date, which is not a secret.
             */
            $table->string('affiliation_date_raw', 80)->nullable();

            $table->date('affiliation_date')->nullable();

            /**
             * `day` or `month`, beside the date.
             *
             * The workbook says a month more often than a day, and §8.3 forbids pretending
             * otherwise. NULL when no date could be read at all.
             */
            $table->string('affiliation_date_precision', 8)->nullable();

            $table->bigInteger('monthly_amount_cop')->nullable();

            /** Normalised social-security tokens. Never free text, never a guessed name. */
            $table->string('eps_token', 120)->nullable();
            $table->string('afp_token', 120)->nullable();
            $table->string('ccf_token', 120)->nullable();
            $table->string('arl_token', 120)->nullable();

            $table->unsignedTinyInteger('arl_risk_class')->nullable();
            $table->string('risk_raw', 40)->nullable();
            $table->string('job_title', 120)->nullable();

            /** Redacted. See the class docblock. */
            $table->text('novelty')->nullable();

            $table->string('operator_ref', 120)->nullable();
            $table->string('payroll_ref', 60)->nullable();
            $table->string('source_reference', 120)->nullable();

            /** Withdrawal evidence: the month named, the count, never a derived date. */
            $table->string('retirement_month_token', 20)->nullable();
            $table->unsignedSmallInteger('retirement_day_count')->nullable();

            /** Everything above, normalised, so a fingerprint has something to hash. */
            $table->jsonb('normalized_payload');

            /** SHA-256 of the canonical JSON of `normalized_payload`. */
            $table->char('fingerprint', 64);

            $table->string('parse_state', 20)->default(ImportRowState::Staged->value);

            $table->timestampsTz();

            $table->index(['legacy_import_id', 'sheet_month']);
            $table->index(['legacy_import_id', 'fingerprint']);
            $table->index(['legacy_import_id', 'client_identity_key']);
            $table->index(['legacy_import_id', 'company_tax_id']);
        });

        DB::statement('ALTER TABLE legacy_import_rows ADD CONSTRAINT legacy_import_rows_state_check
            CHECK (parse_state IN (\'staged\', \'blocked\', \'invalid\', \'duplicate\'))');

        // The date and its precision describe one fact. `month` is the honest answer when a
        // workbook says "March" and nothing else, which is most of them.
        DB::statement('ALTER TABLE legacy_import_rows ADD CONSTRAINT legacy_import_rows_affiliation_date_check
            CHECK ((affiliation_date IS NULL AND affiliation_date_precision IS NULL)
                OR (affiliation_date IS NOT NULL AND affiliation_date_precision IN (\'day\', \'month\')))');

        // A03 has one rule for every amount in the system: whole pesos, positive, no
        // decimals. An amount of zero or a negative one is `invalid_monthly_value` and
        // arrives here as NULL rather than as a number the domain would have to refuse.
        DB::statement('ALTER TABLE legacy_import_rows ADD CONSTRAINT legacy_import_rows_amount_check
            CHECK (monthly_amount_cop IS NULL OR monthly_amount_cop > 0)');

        // Risk classes 1..5, the same shape A02 already enforces on `arl_risk_class`.
        DB::statement('ALTER TABLE legacy_import_rows ADD CONSTRAINT legacy_import_rows_risk_check
            CHECK (arl_risk_class IS NULL OR arl_risk_class BETWEEN 1 AND 5)');

        // Sheet months are the first of a month, like every other month in the system.
        DB::statement("ALTER TABLE legacy_import_rows ADD CONSTRAINT legacy_import_rows_sheet_month_check
            CHECK (date_trunc('month', sheet_month)::date = sheet_month)");

        DB::statement('ALTER TABLE legacy_import_rows ADD CONSTRAINT legacy_import_rows_row_number_check
            CHECK (source_row_number >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_rows');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A02: the client master record.
 *
 * The single most important property of this schema is that a person exists
 * ONCE. The source spreadsheet repeats the same person on every monthly row, so
 * a naive import would create a new client per month and turn the portfolio into
 * thousands of duplicates that share a document number. The uniqueness
 * constraint below is what makes that impossible: the second copy cannot even be
 * inserted, and the importer has to resolve the identity before proceeding.
 *
 * No clinical or unrelated personal data is stored. Date of birth, gender and
 * diagnoses are deliberately absent: none of them are needed to manage
 * affiliations, and collecting them would create obligations this application
 * has no way to meet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();

            // --- Identity -----------------------------------------------------
            $table->string('document_type', 16);
            // A string, never an integer: passports and PPT numbers are not
            // numeric, and a numeric column would coerce them to 0 or truncate
            // a leading zero, silently changing who the record belongs to.
            $table->string('document_number', 32);

            $table->string('first_names', 120);
            $table->string('last_names', 120);

            // --- Contact (all optional: the source data has gaps) ------------
            $table->string('email', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('department', 120)->nullable();

            $table->string('status', 20)->default('active');

            $table->timestamps();

            /*
             | The identity constraint. PostgreSQL UNIQUE treats NULLs as
             | distinct, but `document_number` is NOT NULL, so this is a plain
             | unique index and it is the last line of defence against the
             | duplicate-per-month problem.
             */
            $table->unique(['document_type', 'document_number'], 'clients_document_unique');

            // The list is filtered by status on every screen.
            $table->index('status');

            // The list is sorted by name.
            $table->index(['last_names', 'first_names'], 'clients_name_index');

            // `lower(...)` indexes make the case insensitive search a plain
            // index scan rather than a sequential one.
            $table->index('email', 'clients_email_index');
        });

        /*
         | `status` is a closed set. A CHECK constraint is preferred over a
         | native ENUM because it can be extended with one ALTER TABLE, and it
         | rejects an unknown value at the database rather than in application
         | code that some future write path may forget.
         */
        DB::statement(
            "ALTER TABLE clients ADD CONSTRAINT clients_status_check CHECK (status IN ('active', 'inactive'))"
        );

        DB::statement(
            'ALTER TABLE clients ADD CONSTRAINT clients_document_type_check '
            ."CHECK (document_type IN ('CC', 'CE', 'TI', 'PPT', 'PASSPORT', 'OTHER'))"
        );

        // A stored identity must carry characters. Length only is not enough.
        DB::statement(
            'ALTER TABLE clients ADD CONSTRAINT clients_document_number_check CHECK (length(trim(document_number)) > 0)'
        );

        DB::statement(
            'ALTER TABLE clients ADD CONSTRAINT clients_names_check CHECK (length(trim(first_names)) > 0 AND length(trim(last_names)) > 0)'
        );

        // A partial index over the active rows only. Recreated in the downgrade.
        DB::statement(
            'CREATE INDEX clients_active_index ON clients (status) WHERE status = \'active\''
        );

        // A trimmed, lower cased email is unique when present, so the same
        // mailbox cannot be attached to two clients by accident.
        DB::statement(
            'CREATE UNIQUE INDEX clients_email_unique ON clients (lower(btrim(email))) WHERE email IS NOT NULL AND btrim(email) <> \'\''
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};

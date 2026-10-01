<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A02: companies.
 *
 * The NIT is stored twice on purpose: `tax_id` holds the normalised full value
 * including the verification digit, and `verification_digit` holds that digit on
 * its own so an operator can correct it without rewriting the main number, and so
 * the future importer can flag an entry whose digit does not agree without
 * having to take the value apart again.
 *
 * `tax_id` is nullable and the uniqueness index is partial, because a large part
 * of the source data has no NIT at all. NULLs must not collide with each other.
 *
 * No third party credentials of any kind are stored. A consulting firm
 * administrating payroll needs a company's legal identity and contact details;
 * it has no business holding the customer's portal login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();

            $table->string('legal_name', 180);
            $table->string('trade_name', 180)->nullable();

            // --- Tax identity --------------------------------------------------
            $table->string('tax_id', 32)->nullable();
            $table->string('verification_digit', 2)->nullable();

            // --- Contact ------------------------------------------------------
            $table->string('email', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('department', 120)->nullable();

            $table->string('status', 20)->default('active');

            $table->timestamps();

            $table->index('status');
            $table->index('legal_name');
        });

        DB::statement(
            "ALTER TABLE companies ADD CONSTRAINT companies_status_check CHECK (status IN ('active', 'inactive'))"
        );

        DB::statement(
            'ALTER TABLE companies ADD CONSTRAINT companies_legal_name_check CHECK (length(trim(legal_name)) > 0)'
        );

        DB::statement(
            'ALTER TABLE companies ADD CONSTRAINT companies_verification_digit_check '
            ."CHECK (verification_digit IS NULL OR verification_digit ~ '^[0-9]$')"
        );

        /*
         | Duplicate NIT protection.
         |
         | Partial, so the many companies without a NIT are unaffected, and
         | case insensitive, so `abc` and `ABC` cannot both exist. This is the
         | concurrency guarantee: an application level "does it exist? then
         | insert" is a race, and two operators saving two companies at the same
         | moment would both pass the check. The index does not negotiate.
         */
        DB::statement(
            'CREATE UNIQUE INDEX companies_tax_id_unique ON companies (lower(btrim(tax_id))) '
            ."WHERE tax_id IS NOT NULL AND btrim(tax_id) <> ''"
        );

        DB::statement(
            'CREATE UNIQUE INDEX companies_email_unique ON companies (lower(btrim(email))) '
            ."WHERE email IS NOT NULL AND btrim(email) <> ''"
        );

        // Active set, the hot path for every relationship query.
        DB::statement("CREATE INDEX companies_active_index ON companies (status) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};

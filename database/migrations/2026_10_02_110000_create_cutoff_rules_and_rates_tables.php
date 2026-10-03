<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A03: the cutoff date rules, and the monthly amount a client owes.
 *
 * These two tables are the *configuration* half of A03, and both are strictly
 * historical: a row says what was true from a month onwards and is never rewritten
 * to describe a later month. The `effective_month` column is what makes that
 * possible, and it is the reason neither table needs a "valid from" and "valid to"
 * pair: a later row supersedes an earlier one, and the newest row whose
 * `effective_month` is at or before the period being generated is the answer.
 *
 * ## cutoff_rules: the date a contribution is due
 *
 * "Fecha de corte" is a real business concept and not a field on a form, so it is
 * modelled as three scoped rules rather than one setting:
 *
 *   general   one rule for the whole portfolio
 *   company   one rule per company
 *   client    one rule for a client *within* a company
 *
 * The client scope names a company as well as a client because a client may work
 * for several companies at once, and "this client cuts on the 5th" would then be a
 * statement the system could not act on. The interface calls it an "excepción por
 * cliente" and asks for both.
 *
 * The database enforces the shape of each scope, so an unreachable rule cannot be
 * stored even by a console command: `general` may not carry a company, `company`
 * may not carry a client, and `client` requires both.
 *
 * `month_offset` is 0 or 1 and is configuration rather than an assumption. Whether a
 * contribution is counted in the month it is earned or in the month after is a
 * business fact this module was not told, so it is asked for rather than decided
 * here.
 *
 * ## client_company_rates: the amount a client owes each month
 *
 * Deliberately minimal and deliberately not a formula. There is no rule here that
 * derives an amount from EPS, AFP, ARL, CCF, risk level, salary or minimum wage,
 * because no such rule was supplied and inventing one would produce numbers that
 * look authoritative and are not. The amount is configured per client, per company,
 * effective from a month.
 *
 * Uniqueness on `(client_id, company_id, effective_month)` means the history is a
 * sequence of decisions rather than an overlapping mess: one decision per month per
 * pair, with "the newest one that has started" as the answer.
 *
 * Foreign keys RESTRICT everywhere. A rate that an obligation quotes is part of that
 * obligation's evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutoff_rules', function (Blueprint $table): void {
            $table->id();

            $table->string('scope', 20);

            // Both nullable, because the shape of the rule depends on its scope. The
            // checks below are what make the combination meaningful.
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();

            // The first day of the month this rule starts applying to. Same convention
            // as `monthly_periods.period_month`, so the resolver compares two columns
            // directly.
            $table->date('effective_month');

            // 1..31. A cutoff on the 31st in February means the 28th or 29th: the
            // resolver clamps to the last day of the resolved month rather than
            // failing, because "cut on the 31st" is a real instruction and February
            // is a real month.
            $table->unsignedTinyInteger('cutoff_day');

            // 0: the cutoff day falls in the period's own month.
            // 1: it falls in the following month.
            $table->unsignedTinyInteger('month_offset')->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

        });

        DB::statement(
            'ALTER TABLE cutoff_rules ADD CONSTRAINT cutoff_rules_scope_check '
            ."CHECK (scope in ('general', 'company', 'client'))"
        );

        DB::statement(
            'ALTER TABLE cutoff_rules ADD CONSTRAINT cutoff_rules_day_check '
            .'CHECK (cutoff_day >= 1 AND cutoff_day <= 31)'
        );

        DB::statement(
            'ALTER TABLE cutoff_rules ADD CONSTRAINT cutoff_rules_offset_check '
            .'CHECK (month_offset >= 0 AND month_offset <= 1)'
        );

        DB::statement(
            'ALTER TABLE cutoff_rules ADD CONSTRAINT cutoff_rules_effective_first_day_check '
            .'CHECK (extract(day from effective_month) = 1)'
        );

        /*
         | The scope decides which identifiers may be present.
         |
         | One constraint rather than three, because the shapes are mutually
         | exclusive. The dangerous one is a `client` rule with no company: it reads
         | like an exception for a person, and it would silently never resolve,
         | because a client working for two companies has no single company to apply
         | it to.
         */
        DB::statement(
            'ALTER TABLE cutoff_rules ADD CONSTRAINT cutoff_rules_scope_shape_check '
            ."CHECK ((scope = 'general' AND company_id IS NULL AND client_id IS NULL) "
            ."OR (scope = 'company' AND company_id IS NOT NULL AND client_id IS NULL) "
            ."OR (scope = 'client' AND company_id IS NOT NULL AND client_id IS NOT NULL))"
        );

        // One rule per scope per month. Two general rules starting in October would
        // make the resolver's answer depend on insertion order, which is not a thing
        // a person should have to know in order to predict a due date.
        //
        // Three partial indexes rather than one composite one over
        // `(scope, company_id, client_id, effective_month)`: in that composite the
        // general rows carry NULL in both identifier columns, and NULLs are distinct
        // in a PostgreSQL unique index, so two general rules for the same month would
        // both be accepted. `NULLS NOT DISTINCT` would fix it, but three partial
        // indexes say exactly what is unique and can be read by somebody who does not
        // have to know how PostgreSQL treats NULL in an index.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX cutoff_rules_general_month_unique
                ON cutoff_rules (effective_month)
                WHERE scope = 'general'
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX cutoff_rules_company_month_unique
                ON cutoff_rules (company_id, effective_month)
                WHERE scope = 'company'
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX cutoff_rules_client_month_unique
                ON cutoff_rules (client_id, company_id, effective_month)
                WHERE scope = 'client'
            SQL);

        // Resolution asks, per scope, for the newest rule at or before a month.
        Schema::table('cutoff_rules', function (Blueprint $table): void {
            $table->index(['client_id', 'company_id', 'effective_month'], 'cutoff_rules_client_resolution_index');
            $table->index(['company_id', 'effective_month'], 'cutoff_rules_company_resolution_index');
            $table->index(['scope', 'effective_month'], 'cutoff_rules_scope_resolution_index');
        });

        Schema::create('client_company_rates', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            // The month this amount starts applying to.
            $table->date('effective_month');

            // Whole Colombian pesos, as a 64 bit integer.
            //
            // A decimal column would suggest centavos. This system has never had a
            // requirement for them: the amounts it manages are whole pesos, and a
            // currency type that implies a precision nobody asked for is a place for
            // rounding differences to hide.
            $table->bigInteger('amount_cop');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

        });

        DB::statement(
            'ALTER TABLE client_company_rates ADD CONSTRAINT client_company_rates_amount_positive_check '
            .'CHECK (amount_cop > 0)'
        );

        DB::statement(
            'ALTER TABLE client_company_rates ADD CONSTRAINT client_company_rates_effective_first_day_check '
            .'CHECK (extract(day from effective_month) = 1)'
        );

        Schema::table('client_company_rates', function (Blueprint $table): void {
            // One decision per client, per company, per month.
            $table->unique(
                ['client_id', 'company_id', 'effective_month'],
                'client_company_rates_client_company_month_unique'
            );

            // The resolution query, verbatim.
            $table->index(
                ['client_id', 'company_id', 'effective_month'],
                'client_company_rates_resolution_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_company_rates');
        Schema::dropIfExists('cutoff_rules');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A04: say how precise a historical date is, because the import cannot always know.
 *
 * ## The problem
 *
 * A02's historical model stores a day: `started_on` and `ended_on` are `date` columns and
 * `EffectivePeriod` treats them as `[started_on, ended_on)`. A monthly payroll workbook
 * does not always know a day.
 *
 * The clearest case is a withdrawal written as `RETIRO 15 DIAS MARZO`. The number 15 is
 * not a day of the month and not a date; in this source it is a count of contributed days,
 * and turning it into `15/03` would be inventing a fact. What the source *does* say
 * unambiguously is **March**, so March is the honest answer and the day inside it is not
 * known.
 *
 * Storing `2026-03-15` anyway would be a lie the interface repeats: a collections operator
 * reading "15/03/2026" has no way to know the day was guessed, and the date is what
 * decides whether an obligation was owed in February or in March.
 *
 * ## What this adds
 *
 * A precision column beside each boundary. Nothing about the arithmetic changes:
 * `EffectivePeriod` keeps comparing the stored date, and `[started_on, ended_on)` is
 * unchanged. What changes is what the system is willing to *say* about that date.
 *
 *   day       the source gave a real day          27/03/2026
 *   month     only the month is known             Marzo 2026 (mes aproximado)
 *   unknown   no date at all                      Fecha desconocida
 *
 * `month` exists only for `client_company_assignments` boundaries, where a date is
 * mandatory. `client_affiliations` also allows `started_on IS NULL`, which is what an
 * affiliation observed in a monthly snapshot with no start date looks like, so its start
 * precision has a third value.
 *
 * ## Why default `day`
 *
 * Every row written before this migration has a real date, because nothing else was
 * possible. Defaulting to `day` keeps that true without rewriting a single historical
 * row, which is the one thing A02's model does not tolerate.
 *
 * ## Why the CHECKs are about coherence and not about the import
 *
 * The constraints pair a precision with the presence of its date rather than policing
 * which values the importer may write. `ended_on IS NULL` means "still open", so it has no
 * precision; a start with no date is `unknown` and nothing else. The importer decides
 * *which* precision a row gets; the database refuses the combinations that do not mean
 * anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- client_company_assignments ---------------------------------------
        // A relationship always has a start date, so only `day` and `month` exist here.
        DB::statement("ALTER TABLE client_company_assignments
            ADD COLUMN started_on_precision varchar(8) NOT NULL DEFAULT 'day'");

        DB::statement('ALTER TABLE client_company_assignments
            ADD COLUMN ended_on_precision varchar(8) NULL');

        DB::statement("ALTER TABLE client_company_assignments
            ADD CONSTRAINT assignments_started_precision_check
            CHECK (started_on_precision IN ('day', 'month'))");

        // An open relationship has no end, so it has no end precision either. And an end
        // date and its precision must agree about which one exists.
        DB::statement("ALTER TABLE client_company_assignments
            ADD CONSTRAINT assignments_ended_precision_check
            CHECK ((ended_on IS NULL AND ended_on_precision IS NULL)
                OR (ended_on IS NOT NULL AND ended_on_precision IN ('day', 'month')))");

        // --- client_affiliations ----------------------------------------------
        // `started_on` is nullable here, so the start precision can be `unknown` too.
        DB::statement("ALTER TABLE client_affiliations
            ADD COLUMN started_on_precision varchar(8) NOT NULL DEFAULT 'day'");

        DB::statement('ALTER TABLE client_affiliations
            ADD COLUMN ended_on_precision varchar(8) NULL');

        // The existing rows are corrected **before** the coherence constraint is added,
        // not after. `started_on` has always been nullable, so a NULL start arrived with
        // the column default of `day`, and adding the constraint first would fail with a
        // constraint violation naming a row rather than a concept. The correction is a
        // one-word correction on rows that carry no date at all, so nothing that is
        // recorded is lost: a NULL start said "we do not know when this began", and
        // `unknown` says exactly that.
        $unknownStarts = DB::table('client_affiliations')->whereNull('started_on')->count();

        if ($unknownStarts > 0) {
            DB::table('client_affiliations')
                ->whereNull('started_on')
                ->update(['started_on_precision' => 'unknown']);
        }

        DB::statement("ALTER TABLE client_affiliations
            ADD CONSTRAINT affiliations_started_precision_check
            CHECK (started_on_precision IN ('day', 'month', 'unknown'))");

        DB::statement("ALTER TABLE client_affiliations
            ADD CONSTRAINT affiliations_ended_precision_check
            CHECK ((ended_on IS NULL AND ended_on_precision IS NULL)
                OR (ended_on IS NOT NULL AND ended_on_precision IN ('day', 'month')))");

        // `unknown` and a date are opposites: exactly one of them. Without this a row could
        // claim an unknown start while carrying a date, which is how an approximate date
        // becomes an apparently exact one a few releases later.
        DB::statement("ALTER TABLE client_affiliations
            ADD CONSTRAINT affiliations_started_precision_coherent_check
            CHECK ((started_on IS NULL) = (started_on_precision = 'unknown'))");
    }

    public function down(): void
    {
        // The CHECKs first, because dropping a column takes its constraints with it and
        // writing the order makes it obvious what each statement is responsible for.
        DB::statement('ALTER TABLE client_affiliations DROP CONSTRAINT IF EXISTS affiliations_started_precision_coherent_check');
        DB::statement('ALTER TABLE client_affiliations DROP CONSTRAINT IF EXISTS affiliations_ended_precision_check');
        DB::statement('ALTER TABLE client_affiliations DROP CONSTRAINT IF EXISTS affiliations_started_precision_check');
        DB::statement('ALTER TABLE client_affiliations DROP COLUMN IF EXISTS ended_on_precision');
        DB::statement('ALTER TABLE client_affiliations DROP COLUMN IF EXISTS started_on_precision');

        DB::statement('ALTER TABLE client_company_assignments DROP CONSTRAINT IF EXISTS assignments_ended_precision_check');
        DB::statement('ALTER TABLE client_company_assignments DROP CONSTRAINT IF EXISTS assignments_started_precision_check');
        DB::statement('ALTER TABLE client_company_assignments DROP COLUMN IF EXISTS ended_on_precision');
        DB::statement('ALTER TABLE client_company_assignments DROP COLUMN IF EXISTS started_on_precision');

        // Nothing to restore. The rows this migration touched had a NULL `started_on` before
        // it and have one now, so the rollback loses no information; and the rows whose
        // precision said `day` said it because they had a real day, which is still true.
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A02-R1: the NIT is a base number and a verification digit, not one string.
 *
 * A02 stored `tax_id` as `900123456-3` while also keeping a `verification_digit`
 * column, which meant the same digit lived in two places and uniqueness was
 * decided on a string that contained it. The DIAN treats the number and the digit
 * as separate values, so that is how they are stored now:
 *
 *     tax_id             900123456
 *     verification_digit 3
 *
 * The corrective steps, in this order:
 *
 *  1. read every NIT that still carries a hyphen, split it, and write the digit
 *     into `verification_digit` when that column is empty;
 *  2. stop if two companies end up sharing a base number, naming them, rather than
 *     letting a unique index fail with a message about a key;
 *  3. drop the uniqueness index over the combined value, which no longer describes
 *     the column, and build one over the base number, which is what identifies a
 *     company;
 *  4. check, at database level, that both columns hold what they claim to hold.
 *
 * Nothing is deleted and no digit is recalculated. A digit that disagrees with the
 * base number stays exactly as it was supplied: it is reported as a doubt by the
 * data quality layer, and this migration has no opinion about it.
 *
 * `tax_id` stays nullable throughout, because a large part of the source data has
 * no NIT at all and NULLs must not collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropTheOldUniquenessIndex();
        $this->splitCombinedValues();
        $this->failOnConflictingBaseNumbers();
        $this->createTheBaseUniquenessIndex();
        $this->constrainTheColumns();
    }

    public function down(): void
    {
        // Putting the digit back inside tax_id is possible, but it would undo a
        // representation this migration exists to correct, and it could produce a
        // value longer than the column. The corrective direction is one way.
        throw new RuntimeException(
            'Las migraciones de A02-R1 no se revierten: deshacerlas volvería a mezclar el NIT '
            .'con su dígito de verificación. Restaure una copia anterior de la base de datos si '
            .'necesita volver atrás.'
        );
    }

    /**
     * Move the digit out of `tax_id` and into `verification_digit`.
     *
     * Three shapes arrive from A02 and all three are handled, because a migration
     * that only knows one of them will refuse the databases that use the others:
     *
     *     900111222-3     base and digit together
     *     900.333.444-7   the same, written the way a person writes it
     *     900.555.666     only the base, with separators
     *
     * A value whose `verification_digit` is already filled keeps it: the separated
     * column is where an operator corrects a digit on purpose, and overwriting it
     * with the copy inside the string would throw that work away.
     *
     * Anything that is neither of those shapes is left exactly as it is. The format
     * constraint added below then refuses it with a message about the column, which
     * is the intended outcome: a value this migration cannot read is a value a
     * person should look at, not one to normalise by guesswork.
     */
    private function splitCombinedValues(): void
    {
        DB::table('companies')
            ->whereNotNull('tax_id')
            ->where('tax_id', '<>', '')
            ->orderBy('id')
            ->each(function (object $company): void {
                $value = mb_strtoupper(trim((string) $company->tax_id));

                // Thousand separators and any kind of space carry no meaning. The
                // hyphen does: it is what separates the base from the digit.
                $value = str_replace(["\u{002E}", "\u{0020}", "\u{00A0}", "\u{202F}"], '', $value);
                $value = (string) preg_replace('/-+/', '-', $value);

                if (preg_match('/^(\d{1,12})-(\d)$/', $value, $withDigit) === 1) {
                    $update = ['tax_id' => $withDigit[1]];

                    if ($company->verification_digit === null || trim((string) $company->verification_digit) === '') {
                        $update['verification_digit'] = $withDigit[2];
                    }

                    DB::table('companies')->where('id', $company->id)->update($update);

                    return;
                }

                if (preg_match('/^\d{1,12}$/', $value) === 1) {
                    DB::table('companies')->where('id', $company->id)->update(['tax_id' => $value]);
                }
            });
    }

    /**
     * Stop before an index would stop us, with a message that says which
     * companies are involved.
     */
    private function failOnConflictingBaseNumbers(): void
    {
        $conflicts = DB::table('companies')
            ->selectRaw('lower(btrim(tax_id)) as nit, count(*) as total, array_agg(id order by id) as ids')
            ->whereNotNull('tax_id')
            ->whereRaw("btrim(tax_id) <> ''")
            ->groupByRaw('lower(btrim(tax_id))')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($conflicts->isEmpty()) {
            return;
        }

        $detail = $conflicts
            ->map(fn (object $row): string => sprintf('NIT %s en las empresas %s', $row->nit, $row->ids))
            ->implode('; ');

        throw new RuntimeException(
            'No se puede aplicar la migración de A02-R1: dos o más empresas comparten el mismo NIT '
            ."base, y la unicidad recae ahora sobre ese número. {$detail}. Corrija los registros "
            .'duplicados a mano y vuelva a ejecutar la migración.'
        );
    }

    /**
     * Uniqueness decided on the base number.
     *
     * `900123456-3` and `900123456-7` are one company with two contradictory
     * digits, so the index covers the number alone. Partial, because a large part of
     * the source data has no NIT and NULLs must not collide.
     */
    private function createTheBaseUniquenessIndex(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX companies_tax_id_unique ON companies (lower(btrim(tax_id))) '
            ."WHERE tax_id IS NOT NULL AND btrim(tax_id) <> ''"
        );
    }

    /**
     * Dropped before the rewrite, and this is why.
     *
     * The old index protects the combined value, and the rewrite turns two rows
     * that were distinct under it into the same base number. Left in place, the
     * second rewrite fails with a raw unique violation and the operator never finds
     * out which companies are in conflict. Dropping it first lets the explicit
     * check above do its job.
     */
    private function dropTheOldUniquenessIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS companies_tax_id_unique');
    }

    private function constrainTheColumns(): void
    {
        if (! Schema::hasColumn('companies', 'verification_digit')) {
            DB::statement('ALTER TABLE companies ADD COLUMN verification_digit varchar(2) NULL');
        }

        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_tax_id_format_check');
        DB::statement(
            'ALTER TABLE companies ADD CONSTRAINT companies_tax_id_format_check '
            ."CHECK (tax_id IS NULL OR tax_id ~ '^[0-9]{1,12}$')"
        );

        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_verification_digit_check');
        DB::statement(
            'ALTER TABLE companies ADD CONSTRAINT companies_verification_digit_check '
            ."CHECK (verification_digit IS NULL OR verification_digit ~ '^[0-9]$')"
        );
    }
};

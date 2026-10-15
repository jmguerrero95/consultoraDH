<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIX-LOCAL-01: widen the `phone` columns, which the delivered workbook overflows.
 *
 * ## What was wrong
 *
 * Staging the real workbook died on its first row with
 * `SQLSTATE[22001]: String data, right truncated: value too long for type character varying(40)`,
 * and the parse job — correctly, per §4.2 — reported only `parse_failed` and the exception's
 * class name. So an operator uploading the one file this module exists to read saw "it failed"
 * and a support ticket, while the cause was one column being 3 characters too narrow.
 *
 * The delivered workbook's phone cell on that row is a **mask**, not a number: 43 characters of
 * `+`. The source masks its phone column, and a masked cell is as wide as the column behind it.
 * Nothing about it is an error in the data, so the columns are wrong, not the values.
 *
 * Applying the workbook then hit the same wall in two more places — `clients.phone` on the same
 * masked value, and `companies.phone` — because the staging table and both masters store the
 * same source contact value and all three had been sized for a phone number rather than for a
 * cell. Fixing one at a time would have meant discovering the next one only after the next full
 * run, so all three move together.
 *
 * ## Why 180 and not 44
 *
 * Because "widen it to the longest value in today's file" is how the next revision of this
 * workbook fails the same way. The 2027 file is a different file. 180 puts `phone` beside the
 * other raw source contact columns rather than leaving it the narrowest contact field in the
 * schema on a measurement.
 *
 * ## Why widen rather than truncate, null or discard
 *
 * Truncating would store a phone number that is not the one in the file, silently, and the
 * resulting record would be indistinguishable from a real value. Nulling it would discard the
 * fact that the source has a masked phone. Staging exists to be a faithful copy of what the
 * review screen shows the reviewer, so the column has to hold what the file holds — and §4.3's
 * redaction, not a `varchar` bound, is what decides what may be stored.
 *
 * ## Why this is safe on a database that already has rows
 *
 * `varchar` → `varchar` with a larger bound is a non-rewriting change in PostgreSQL: existing
 * values are untouched, nothing is truncated, nothing is locked out for long. `down()` restores
 * the original bound and is the only direction that could fail — it refuses if any stored value
 * would not fit, rather than silently truncating somebody's record.
 */
return new class extends Migration
{
    /** @var list<array{table: string, column: string, widenTo: int}> */
    private const COLUMNS = [
        ['table' => 'legacy_import_rows', 'column' => 'phone', 'widenTo' => 180],
        ['table' => 'clients', 'column' => 'phone', 'widenTo' => 180],
        ['table' => 'companies', 'column' => 'phone', 'widenTo' => 180],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $spec) {
            Schema::table($spec['table'], function (Blueprint $table) use ($spec): void {
                $table->string($spec['column'], $spec['widenTo'])->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $spec) {
            $tooLong = DB::table($spec['table'])
                ->whereNotNull($spec['column'])
                ->whereRaw('char_length('.$spec['column'].') > 40')
                ->count();

            if ($tooLong > 0) {
                // Refuse rather than truncate: rolling this back would silently alter staged
                // rows and master records a reviewer may already have decided on.
                throw new RuntimeException(sprintf(
                    'Cannot narrow %s.%s back to 40: %d row(s) hold a longer value. '
                    .'Truncating them would change staged evidence and client records.',
                    $spec['table'],
                    $spec['column'],
                    $tooLong,
                ));
            }
        }

        foreach (self::COLUMNS as $spec) {
            Schema::table($spec['table'], function (Blueprint $table) use ($spec): void {
                $table->string($spec['column'], 40)->nullable()->change();
            });
        }
    }
};

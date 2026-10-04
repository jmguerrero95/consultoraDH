<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable `unaccent`, so a name can be found however it is typed.
 *
 * ## The defect
 *
 * Every search in this application compares a needle folded in PHP against a column folded
 * by PostgreSQL:
 *
 *     lower(column) LIKE '%' || mb_strtolower($term) || '%'
 *
 * That works only when both sides fold the same way, and in this database they do not. The
 * test and production databases are created with the `C` collation:
 *
 *     select datcollate from pg_database where datname = current_database();  -- C
 *
 * The C locale case-folds ASCII and nothing else, so `lower('Única')` returns `Única`
 * while PHP's `mb_strtolower('Única')` returns `única`. The comparison is then
 *
 *     'Única' LIKE '%única%'   -- false
 *
 * and the person is in the system but cannot be found by typing their name.
 *
 * ## Why this bites unevenly, which is why it survived
 *
 * `Gómez` searches fine. The `ó` is already lowercase in the column and PHP lowercases it
 * in the needle, so the two agree without the database having to fold anything. Only the
 * **capital** accented letters break — and the capital accented letter is nearly always the
 * first letter of a name:
 *
 *     Única, Ñoño, Épsilon, Ángel, Óscar, Iñigo
 *
 * so an accented person is searchable by their surname and unsearchable by their first
 * name. Neither spelling finds them either: typing `unica` misses `Única` for the same
 * reason, because there is no accent to strip on either side of the comparison.
 *
 * This is not a single screen. Clients, companies, social security entities, payments, the
 * portfolio and the billing configuration all search names, so every one of them had the
 * same hole. That is why it is fixed in the database rather than in a controller: the
 * comparison has to fold the same way everywhere, and one place is the only way to be sure.
 *
 * ## How the fix folds
 *
 * `lower(unaccent(<column>)) LIKE lower(unaccent(?))`. Both sides are folded **in SQL**, so
 * PHP and PostgreSQL cannot disagree about what a term means:
 *
 * - `unaccent` first, so the value is ASCII by the time `lower()` sees it, which is what
 *   makes the C collation's ASCII-only folding sufficient.
 * - `lower` second, which then works on plain ASCII.
 *
 * Folding the needle in SQL rather than transliterating it in PHP is deliberate. PHP and
 * `unaccent` do not have the same rules — PHP's `Str::ascii()` and `unaccent` both turn `ß`
 * into `ss` today, but nothing guarantees they will keep agreeing, and a disagreement would
 * present as a person who cannot be found. One folding implementation cannot disagree with
 * itself.
 *
 * `%` and `_` pass through `unaccent` unchanged, so the escaping in `SafeSearch` still means
 * what it says: a typed `100%` is a percent sign.
 *
 * ## What this does not do
 *
 * `unaccent` is `STABLE`, not `IMMUTABLE` — it reads a dictionary — so it cannot appear in a
 * functional index or a generated column. That costs nothing here, because these searches
 * already lead with `%` and cannot use a `btree` index at all. What it does mean is that
 * "searchable no matter how it is typed" is a comparison the database performs, not an index
 * it can precompute.
 *
 * The `C` collation is left alone. It also decides how names *sort* — `Zapata` before
 * `Álvarez`, byte by byte — and changing `datcollate` would reach every `ORDER BY`, every
 * index and every existing sort order in the product. That is a separate decision, recorded
 * rather than taken here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    /**
     * Intentionally a no-op. The extension is deliberately left in place.
     *
     * ## Why this cannot be undone honestly
     *
     * `up()` runs `CREATE EXTENSION IF NOT EXISTS unaccent`. That statement cannot report
     * whether it created the extension or found it already there — the database does not
     * record which of the two happened, and by the time `down()` runs, the only evidence
     * left is that the extension exists.
     *
     * So this migration does not know whether it owns it. Two states are indistinguishable
     * from here and they need opposite rollbacks:
     *
     *  1. This migration created `unaccent`. Rolling back should remove it, or the database
     *     is left with an extension nobody declared.
     *  2. `unaccent` was already installed — by a DBA, by a base image, by another feature —
     *     or another application feature depends on it. Rolling back must **not** remove it,
     *     and dropping it would break that other consumer.
     *
     * The previous version of this file chose case 1 unconditionally and dropped the
     * extension. That is the destructive branch, taken on no evidence, in the one case where
     * being wrong breaks something this file does not own.
     *
     * ## Why a no-op rather than a preflight
     *
     * A preflight cannot answer the question either. "Is anything else using `unaccent`?"
     * is not answerable from inside this transaction: PostgreSQL has no catalogue view of
     * which columns, functions or other schemas reference an extension, and the honest answer
     * for this project is that `SafeSearch::match()` is one caller and cannot prove it is the
     * only one.
     *
     * Leaving the extension behind is the safe direction. Its cost is that a database which
     * only ever needed this migration keeps an extension after rolling it back — inert,
     * owned by nobody, removable by hand with one statement. The cost of the alternative is
     * dropping a shared extension out from under code that may depend on it, which is
     * unrecoverable without a restore.
     *
     * ## `up()` is unchanged
     *
     * The fix is to the rollback only. Rolling the migration forward still installs the
     * extension, which is what the application needs; nothing about the application's
     * behaviour changes.
     */
    public function down(): void
    {
        // No-op, on purpose. `CREATE EXTENSION IF NOT EXISTS` cannot report whether this
        // migration created the extension, so dropping it here would be destroying a shared
        // database object on the strength of a guess. The reasoning is in the docblock above.
    }
};

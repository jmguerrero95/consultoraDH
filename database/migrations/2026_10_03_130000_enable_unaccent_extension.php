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

    public function down(): void
    {
        // Safe to drop: nothing outside a `LIKE` comparison depends on it, and
        // `SafeSearch::match()` is the only caller. A deployment that runs this down while
        // the application is live will make those searches fail loudly rather than quietly,
        // which is the right way round.
        DB::unprepared('DROP EXTENSION IF EXISTS unaccent');
    }
};

<?php

declare(strict_types=1);

namespace App\Support\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A search needle that cannot become a pattern.
 *
 * ## The bug this closes
 *
 * The payments list built its needle with `lower(column) LIKE $needle` where `$needle` was
 * `'%' . $value . '%'` and nothing escaped the `%` or `_` the operator had typed. So a search
 * for `100%` matched everything containing `100`, and a search for `a_b` matched `axb`. Both
 * are wrong answers rather than errors, which is the worst kind.
 *
 * ## Case
 *
 * The needle is lowercased here as well as in the query. `lower(first_names) LIKE 'ana%'`
 * does not match `Ana María`, because the column is folded and the literal is not — the
 * review names this exact case. Folding both sides is what makes the search behave the way
 * a person expects when they type the first two letters of a name.
 *
 * ## Escaping
 *
 * `addcslashes` for `%`, `_` and the escape character itself, with `ESCAPE '\'` appended to
 * the pattern. Backslash is chosen because it is not special in either SQL or the driver.
 *
 * ## Case and accents
 *
 * The comparison is written by `match()` and `fullNameMatch()`, never by hand, and both fold
 * **both sides in SQL**:
 *
 *     lower(unaccent(column)) LIKE lower(unaccent(?)) ESCAPE '\'
 *
 * That is not decoration. The databases are created with the `C` collation, and the C locale
 * case-folds ASCII and nothing else — so `lower('Única')` is `Única`, while PHP's
 * `mb_strtolower('Única')` is `única`, and `lower(column) LIKE '%única%'` matches nobody.
 * A person with an accented capital in their name was in the system and unfindable.
 *
 * Two consequences worth stating, because both look like bugs if they are not:
 *
 * - **Fold in SQL, not in PHP.** Transliterating the needle in PHP would mean two
 *   implementations of the same rules, which is one more than there should be; the day they
 *   disagree, a person cannot be found and there is nothing to look at. `likeNeedle()` still
 *   lowercases, which is harmless and belt-and-braces, but `unaccent` is what decides.
 * - **`unaccent` first, `lower` second.** In that order the value is ASCII by the time
 *   `lower()` sees it, which is exactly what makes the C collation's ASCII-only folding
 *   enough. The other way round does not work.
 *
 * `unaccent` is `STABLE`, not `IMMUTABLE`, so it cannot be indexed — irrelevant here, because
 * a leading `%` already rules out a `btree` index.
 *
 * The rule itself only validates; it does not build the pattern. It exists so the *presence*
 * of a wildcard is either refused or documented, and so a caller cannot forget the escaping
 * step by accident. This project's queries call `likeNeedle()` and `match()`.
 */
final class SafeSearch implements ValidationRule
{
    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail('La búsqueda debe ser texto.');
        }

        if (mb_strlen($value) > 200) {
            $fail('La búsqueda es demasiado larga.');
        }
    }

    /**
     * The needle for a `LIKE` comparison: lowercased, wrapped, and escaped.
     *
     * Centralised so every searchable screen escapes the same way. A helper that each query
     * calls is a rule that can be forgotten in exactly one place.
     *
     * The lowercasing here is redundant with `match()`, which folds the needle again in SQL,
     * and is kept because it costs nothing and means a needle handed to any other kind of
     * comparison is still folded. Escape first, fold second: folding after escaping cannot
     * introduce a wildcard, because folding only rewrites letters.
     */
    public static function likeNeedle(string $needle): string
    {
        $escaped = addcslashes(mb_strtolower(trim($needle)), '\\%_');

        return '%'.$escaped.'%';
    }

    /**
     * The `ESCAPE` clause that goes with `likeNeedle()`.
     *
     * Without it the backslashes are literal characters and the escaping achieves nothing.
     */
    public static function likeEscape(): string
    {
        return " ESCAPE '\\'";
    }

    /**
     * The SQL that matches a **whole name** against two columns at once.
     *
     * ## Why this exists
     *
     * Searching `first_names` and `last_names` independently means a person's name can be
     * spelled only by matching each column on its own. With
     *
     *     first_names = "Ana María"
     *     last_names  = "Gómez"
     *
     * the term `Ana María Gómez` matches **neither** column, so a person who is in the
     * system cannot be found by typing their name. `Ana` works. `Gómez` works.
     * The full name — the one thing everybody actually types — does not.
     *
     * This is not cosmetic for this project: the billing configuration screen picks a
     * client through this search, so an exception rule cannot be configured for a client
     * by name, and the payments and portfolio screens have the same problem.
     *
     * `concat_ws(' ', …)` rather than `first_names || ' ' || last_names`, because `||`
     * turns a NULL name into NULL for the whole expression, and a NULL comparison is not a
     * match. `concat_ws` skips missing parts, so a person with one name still matches.
     *
     * The columns are bare rather than qualified — `clients.first_names` inside a `whereHas`
     * subquery would name a table the subquery cannot see. The callers that need
     * qualification build the expression themselves and pass it to `match()`.
     *
     * @param  string  $firstColumn  e.g. `first_names` or `obligations.first_names`
     */
    public static function fullNameMatch(string $firstColumn, string $lastColumn): string
    {
        return self::match(sprintf("concat_ws(' ', %s, %s)", $firstColumn, $lastColumn));
    }

    /**
     * The SQL that matches one expression against a needle, folded on both sides.
     *
     * The single place a `LIKE` against a searched name is written. Written by hand it is one
     * forgotten `unaccent` away from quietly matching a different set of people depending on
     * which controller is asking, which is the exact shape of the defect this closes.
     *
     * `%` and `_` reach the pattern through `likeNeedle()` and pass `unaccent` untouched, so
     * the escaping still decides what they mean.
     *
     * @param  string  $expression  a column, or a SQL expression over columns such as
     *                              `coalesce(trade_name, '')`
     */
    public static function match(string $expression): string
    {
        return sprintf(
            'lower(unaccent(%s)) LIKE lower(unaccent(?))%s',
            $expression,
            self::likeEscape(),
        );
    }
}

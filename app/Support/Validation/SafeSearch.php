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
 * The rule itself only validates; it does not build the pattern. It exists so the *presence*
 * of a wildcard is either refused or documented, and so a caller cannot forget the escaping
 * step by accident. This project's queries call `likeNeedle()`.
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
}

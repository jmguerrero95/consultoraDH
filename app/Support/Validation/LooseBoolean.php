<?php

declare(strict_types=1);

namespace App\Support\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A boolean as it actually arrives: from a checkbox, a query string or JSON.
 *
 * ## Why Laravel's own `boolean` rule is not enough
 *
 * `boolean` accepts `true, false, 0, 1, "0", "1"` — the **strings** `"true"` and
 * `"false"` are not in that list. So a request carrying `requires_reconciliation=false`,
 * which is exactly what an unchecked HTML checkbox sends, is refused with
 * "El campo sólo por conciliar debe ser verdadero o falso".
 *
 * That is not a cosmetic problem. It is how `overdue=false` and
 * `requires_reconciliation=false` reached the query in the first place: the interface sends
 * the literal string, and the service read it with `boolean()`, which *does* understand it
 * through `filter_var`. Adding a strict rule without teaching it the string forms would have
 * turned a filtering bug into a 422 — a different failure, on the same input.
 *
 * ## What is accepted
 *
 * `true`/`false` as booleans or strings, `1`/`0` as integers or strings, and the empty
 * string, which is what a form sends for an unchecked box with no value attribute.
 *
 * Anything else is refused: `"yes"`, `"on"` and `""` are ambiguous about intent, and a filter
 * that guesses is a filter that answers a question nobody asked.
 */
final class LooseBoolean implements ValidationRule
{
    private const TRUE = ['1', 'true', 'on', 'yes'];

    private const FALSE = ['0', 'false', 'off', 'no'];

    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_bool($value) || is_int($value) || $value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail('Este valor debe ser verdadero o falso.');
        }

        $normalised = mb_strtolower(trim($value));

        if ($normalised === '') {
            return;
        }

        if (in_array($normalised, self::TRUE, true) || in_array($normalised, self::FALSE, true)) {
            return;
        }

        $fail('Este valor debe ser verdadero o falso.');
    }

    /**
     * The value as a real boolean.
     *
     * `filter_var` with `FILTER_NULL_ON_FAILURE`, so an unrecognised value is `null` — "not
     * a boolean" — rather than silently false. A filter that turns a typo into "false"
     * quietly changes the population the screen is showing.
     */
    public static function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}

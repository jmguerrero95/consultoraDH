<?php

declare(strict_types=1);

namespace App\Support\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A whole number of pesos, with no centavos and no silent rounding.
 *
 * ## Why this is not just `integer`
 *
 * Laravel's `integer` rule accepts the string `235000.50` in some configurations and
 * numeric input like `235000.7`. This system has never represented centavos: the columns are
 * `BIGINT` of whole pesos, and the reason is written in the migrations.
 *
 * So accepting `235000.50` and storing `235000` loses fifty pesos silently, and accepting
 * `235000.7` loses seventy centavos. Both look like success to the caller. An amount in this
 * system either is a whole peso or is not a valid amount, and the difference between
 * "235000.50 was meant" and "235000.50 was typed by accident" is not something this layer
 * can know — so it refuses and lets a person say which it was.
 *
 * A leading minus is allowed, because a correction may legitimately reduce an amount. Zero
 * is a separate matter and is refused by the caller that cares, since a zero adjustment is
 * a comment rather than an adjustment.
 */
final class MoneyWholePesos implements ValidationRule
{
    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value)) {
            return;
        }

        if (is_float($value)) {
            if (floor($value) !== $value) {
                $fail('El valor debe ser un número entero de pesos: este sistema no representa centavos.');
            }

            return;
        }

        if (! is_string($value)) {
            $fail('El valor debe escribirse como un número entero de pesos.');
        }

        if (preg_match('/^-?\\d+$/', trim($value)) !== 1) {
            // Covers `235000.50`, `235.000,50`, `1e5`, `abc` and an empty string in one
            // refusal, which is what a money field should do rather than trying to be clever
            // about which separators the operator used.
            $fail('El valor debe ser un número entero de pesos, sin decimales ni centavos.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A first-day-of-month date, checked as a real calendar position.
 *
 * ## Why a regex was not enough
 *
 * The rules these replaced were `regex:/^\d{4}-\d{2}-01$/`, which accepts `2026-13-01`
 * and `2026-99-01` without complaint: thirteen is not a month, and a regex cannot tell.
 * The failure then happened somewhere else — `Carbon::parse` threw, or Postgres refused
 * the date — and reached the operator as a 500 with no field to point at. A month that
 * does not exist is a mistake in the request, and a mistake in the request is a 422.
 *
 * ## What is accepted
 *
 * Two shapes, because both are in use at the boundary and neither is wrong:
 *
 *   `2026-01-01`   a full date that must fall on the first of the month
 *   `2026-01`      the month key the interface and the routes use
 *
 * Anything else — including a date in the middle of a month, `2026-1`, `26-01-01`,
 * `2026-01-02` or a month outside 1..12 — is refused.
 *
 * ## Applied before the domain parses
 *
 * `MonthlyPeriod` is where the arithmetic lives, and it throws on an impossible month
 * because a value object handed `2026-13` has nothing honest to return. This rule
 * exists so that the throw happens at the edge with a field name attached, instead of
 * propagating out of an action as a server error.
 */
final class FirstDayOfMonth implements ValidationRule
{
    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('El mes debe escribirse como texto, como 2026-01-01.');

            return;
        }

        $trimmed = trim($value);

        // Exactly two digits for the month, because `MonthlyPeriod::fromKey` requires
        // the `YYYY-MM` shape with a two digit month and `2026-1` is not that key.
        // Accepting it here would only move the failure one layer down, into a value
        // object throwing instead of a field being named.
        if (preg_match('/^(\d{4})-(\d{2})$/', $trimmed, $key) === 1) {
            $this->assertRealMonth($fail, (int) $key[1], (int) $key[2], 'AAAA-MM');

            return;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{1,2})$/', $trimmed, $full) === 1) {
            if ((int) $full[2] < 1 || (int) $full[2] > 12) {
                $fail('El mes debe estar entre 1 y 12.');

                return;
            }

            if ((int) $full[3] !== 1) {
                $fail('La fecha debe ser el primer día del mes, como 2026-01-01.');

                return;
            }

            $this->assertRealYear($fail, (int) $full[1]);

            return;
        }

        $fail('El mes debe escribirse como 2026-01-01 o 2026-01.');
    }

    /**
     * @param  Closure(string): void  $fail
     */
    private function assertRealMonth(Closure $fail, int $year, int $month, string $shape): void
    {
        if ($month < 1 || $month > 12) {
            $fail('El mes debe estar entre 1 y 12.');

            return;
        }

        $this->assertRealYear($fail, $year, $shape);
    }

    /**
     * The same bound `MonthlyPeriod::fromYearMonth` applies. A year of 0000 or 9999 is
     * not a month anybody bills, and PostgreSQL's own date range would refuse it as a
     * 500 rather than a 422.
     *
     * @param  Closure(string): void  $fail
     */
    private function assertRealYear(Closure $fail, int $year, string $shape = 'AAAA-MM-DD'): void
    {
        if ($year < 1900 || $year > 2200) {
            $fail('El año debe ser razonable, escrito como cuatro dígitos.');
        }
    }
}

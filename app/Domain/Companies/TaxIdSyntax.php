<?php

declare(strict_types=1);

namespace App\Domain\Companies;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts the NIT spellings a person or an importer actually uses.
 *
 * One rule, used by both the create and the update request, because a validity
 * check that exists in two places is a check that will eventually disagree with
 * itself. It exists at all because the column holds digits only and the database
 * enforces that: without it, `ABC123` was accepted by validation, rejected by
 * PostgreSQL, and answered to the caller as a server error.
 *
 * Accepted:
 *
 *     900123456
 *     900.123.456
 *     900 123 456
 *     900123456-3
 *     900.123.456-3
 *
 * Rejected, because they cannot be a NIT and would only reach the database to be
 * refused there:
 *
 *     ABC123        letters
 *     900-12-34     the separator is not where it belongs
 *     900123456-33  two digits after the separator
 *     900123456-X   a letter where a digit belongs
 *
 * The digit is not checked against the number. Nothing here calculates what the
 * verification digit should be, so a value that disagrees is carried to the domain
 * to be reported as a doubt rather than refused as invalid.
 */
final class TaxIdSyntax implements ValidationRule
{
    /**
     * The canonical shape: a base number, optionally followed by one digit.
     *
     * The group separators are accepted anywhere between digits, because that is
     * how people write one, and removed before the comparison.
     */
    private const PATTERN = '/^\d{1,12}(-\d)?$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('El NIT debe ser un texto.');

            return;
        }

        if (self::isValid($value)) {
            return;
        }

        $fail('El NIT debe tener hasta 12 dígitos, con el dígito de verificación separado por un guion (por ejemplo 900123456-3).');
    }

    /**
     * Whether the value is a NIT in any of the accepted spellings.
     */
    public static function isValid(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        return preg_match(self::PATTERN, TaxId::split($value)['tax_id'].self::digitSuffix($value)) === 1;
    }

    /**
     * The part after the hyphen, if there is one.
     *
     * `TaxId::split()` returns the base and the digit separately, and this puts
     * them back together so one pattern can judge both.
     */
    private static function digitSuffix(string $value): string
    {
        $parts = TaxId::split($value);

        return $parts['verification_digit'] === null ? '' : '-'.$parts['verification_digit'];
    }
}

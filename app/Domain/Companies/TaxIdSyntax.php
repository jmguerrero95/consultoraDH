<?php

declare(strict_types=1);

namespace App\Domain\Companies;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Strict NIT validation, and the only parser the HTTP boundary trusts.
 *
 * `TaxId::split()` is deliberately tolerant: it collapses repeated hyphens, strips
 * stray separators and hands back whatever it can make sense of, because the places
 * that use it are displaying and comparing values that already exist. Validating a
 * *request* with it was wrong: a malformed value was quietly normalised into
 * something that looked valid, so `-900123456-3` and `900123456--` were accepted and
 * stored as `900123456` with a digit. A tolerant parser cannot be the source of
 * truth for strict validation, so the accepted grammar is written out here.
 *
 * The steps, in order, and nothing is accepted that is not exactly one of them:
 *
 *   1. trim the outer whitespace, which is not part of the value;
 *   2. remove the grouping separators a person may type: period, space, non
 *      breaking space, narrow no-break space;
 *   3. require the complete remaining string to match `^[0-9]{1,12}(-[0-9])?$`;
 *   4. only then split it into the number and the digit.
 *
 * Rejected, among others: leading or trailing hyphens (`-900123456-3`,
 * `900123456-3-`), repeated hyphens (`--900123456-3`, `900123456--`), letters,
 * two digits after the separator, and anything else the pattern does not describe.
 *
 * The digit is not checked against the number. Nothing here calculates what the
 * verification digit should be, so a value that disagrees is carried to the domain
 * to be reported as a doubt rather than refused as invalid.
 */
final class TaxIdSyntax implements ValidationRule
{
    /**
     * The complete canonical grammar: a base number, optionally and one digit after
     * a single hyphen. Anchored at both ends, so nothing is left over.
     */
    private const PATTERN = '/^\d{1,12}(-\d)?$/';

    /** The separators people use to group a long number. */
    private const GROUP_SEPARATORS = ["\u{002E}", "\u{0020}", "\u{00A0}", "\u{202F}"];

    /**
     * @return array{tax_id: string, verification_digit: string|null}|null
     *                                                                     the two parts, or null when the value is not a NIT at all
     */
    public static function parse(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);

        // Step 2 and nothing else. Notably this does not collapse repeated hyphens:
        // `900123456--` has to stay malformed rather than become `900123456-`.
        $value = str_replace(self::GROUP_SEPARATORS, '', $value);

        if (preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }

        [$number, $digit] = array_pad(explode('-', $value, 2), 2, null);

        return [
            'tax_id' => $number,
            'verification_digit' => $digit,
        ];
    }

    public static function isValid(?string $raw): bool
    {
        return self::parse($raw) !== null;
    }

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
}

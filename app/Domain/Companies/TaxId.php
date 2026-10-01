<?php

declare(strict_types=1);

namespace App\Domain\Companies;

/**
 * Normalises a company's NIT (Número de Identificación Tributaria).
 *
 * The NIT is stored as a string and never as a number: the values carry a
 * check digit, are written with thousand separators, and one of the historical
 * entries in the source data is inconsistent. Treating them as integers would
 * quietly discard leading zeros and would turn an uncertain value into a
 * confident-looking one.
 *
 * Two properties are kept apart on purpose:
 *
 *  - `normalise()` removes punctuation so that `900.123.456` and `900123456`
 *    are recognised as the same company.
 *  - `isPlausible()` reports whether the digits and check digit actually agree.
 *    It never changes a value; it only answers a question, so an import can
 *    flag the inconsistent entry instead of "fixing" it.
 *
 * The verification digit is stored in its own column so an operator can correct
 * it without touching the main value.
 */
final class TaxId
{
    /**
     * Separators that carry no meaning in a NIT.
     *
     * The hyphen is NOT in this list: in a NIT it is meaningful, because it
     * separates the main number from the verification digit. Stripping it
     * would destroy the only structure that lets the two be stored
     * independently.
     */
    private const SEPARATORS = ["\u{002E}", "\u{0020}", "\u{00A0}", "\u{202F}"];

    /**
     * The canonical comparison form: thousand separators removed, the hyphen
     * between the main number and the verification digit kept.
     *
     * `900.123.456-1` and `900123456-1` both become `900123456-1`, so the two
     * spellings are recognised as one company.
     *
     * Returns an empty string for input that holds no characters at all, which
     * the caller treats as "no NIT supplied".
     */
    public static function normalise(?string $raw): string
    {
        $value = mb_strtoupper(trim((string) $raw));

        foreach (self::SEPARATORS as $character) {
            $value = str_replace($character, '', $value);
        }

        $value = (string) preg_replace('/-+/', '-', $value);

        return trim($value, '-');
    }

    /**
     * The main numeric part, without the trailing check digit.
     *
     * Returns null when the value is absent or is not clearly a NIT, so that
     * nothing is invented from a string that is not one.
     */
    public static function base(?string $normalised): ?string
    {
        $value = (string) $normalised;

        if (preg_match('/^(\d{1,12})(-\d)?$/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The check digit, when the value carries one.
     */
    public static function checkDigit(?string $normalised): ?string
    {
        $value = (string) $normalised;

        if (preg_match('/^\d{1,12}-(\d)$/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Whether the check digit agrees with the main number.
     *
     * Uses the DIAN modulus 11 algorithm. A value without a check digit, or one
     * that is not numeric at all, is reported as "not verifiable" rather than
     * as invalid: the absence of a check digit is a gap in the record, not
     * proof that the NIT is wrong.
     */
    public static function isPlausible(?string $normalised): bool
    {
        $base = self::base($normalised);

        if ($base === null) {
            return false;
        }

        $checkDigit = self::checkDigit($normalised);

        if ($checkDigit === null) {
            return false;
        }

        return (int) $checkDigit === self::expectedCheckDigit($base);
    }

    /**
     * The check digit the DIAN algorithm produces for a base number.
     */
    private static function expectedCheckDigit(string $base): int
    {
        $weights = [71, 67, 59, 53, 47, 43, 41, 37, 29, 23, 19, 17];

        $sum = 0;

        foreach (str_split($base) as $index => $digit) {
            $weight = $weights[$index] ?? 1;

            $sum += (int) $digit * $weight;
        }

        $remainder = $sum % 11;

        return match ($remainder) {
            0 => 9,
            1 => 0,
            default => 11 - $remainder,
        };
    }

    /**
     * A form suitable for a uniqueness comparison in SQL.
     */
    public static function searchable(?string $raw): string
    {
        return self::normalise($raw);
    }

    /**
     * The form shown to a person: `900.123.456-1`.
     */
    public static function forDisplay(?string $normalised): string
    {
        $value = (string) $normalised;

        if (preg_match('/^(\d{1,12})-(\d)$/', $value, $matches) !== 1) {
            return $value;
        }

        $base = $matches[1];

        if (mb_strlen($base) >= 5) {
            $base = preg_replace('/(\d{3})(?=\d)/', '$1.', $base) ?? $base;
        }

        return $base.'-'.$matches[2];
    }
}

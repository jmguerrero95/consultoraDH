<?php

declare(strict_types=1);

namespace App\Domain\Companies;

/**
 * Takes a company's NIT apart into the two values it really is.
 *
 * The DIAN treats a NIT as a base number and a verification digit (dígito de
 * verificación), and Consultora DH stores them apart:
 *
 *     tax_id             900123456
 *     verification_digit 3
 *
 * Both as columns, so an operator can correct one without touching the other and
 * so a future import can hold both without taking the string apart again. A
 * person types `900.123.456-3`, which is the way it is printed, and the two parts
 * are separated here.
 *
 * Two decisions worth stating outright:
 *
 *  - **The digit is never calculated.** There is a well known modulus eleven
 *    algorithm for it, and an earlier version of this class implemented it. It was
 *    removed. Without an authoritative source of DIAN test vectors to test it
 *    against, a calculator that looks authoritative is worse than no calculator:
 *    it would silently rewrite a historical value and turn a doubt into a fact
 *    that nobody questioned. What arrives is what is stored.
 *
 *  - **Uniqueness is decided on the base number.** `900123456-3` and
 *    `900123456-7` are one company with two contradictory digits, not two
 *    companies, so the index that protects uniqueness covers `tax_id` alone. A
 *    disagreement about the digit is reported as a doubt, not resolved by
 *    inventing one of the two answers.
 */
final class TaxId
{
    /**
     * Separators a person may type between the groups of a NIT: the period, the
     * ordinary space, the non breaking one and the narrow no-break space.
     *
     * The hyphen is not in the list: in `900123456-3` it separates the base from
     * the digit, and that structure is the only thing that makes the split
     * possible.
     */
    private const GROUP_SEPARATORS = ["\u{002E}", "\u{0020}", "\u{00A0}", "\u{202F}"];

    /**
     * The two parts of a NIT, already validated.
     *
     * @return array{tax_id: string, verification_digit: string|null}
     */
    public static function split(?string $raw): array
    {
        $value = mb_strtoupper(trim((string) $raw));

        foreach (self::GROUP_SEPARATORS as $separator) {
            $value = str_replace($separator, '', $value);
        }

        // Several hyphens are a typo rather than a structure; one is.
        $value = (string) preg_replace('/-+/', '-', $value);

        if (preg_match('/^(\d{1,12})(?:-(\d))?$/', $value, $matches) === 1) {
            return [
                'tax_id' => $matches[1],
                'verification_digit' => $matches[2] ?? null,
            ];
        }

        // Anything else is returned whole and unverified, so the caller can decide
        // what to do about it rather than having a guess silently applied.
        return [
            'tax_id' => trim($value, '-'),
            'verification_digit' => null,
        ];
    }

    /**
     * The base number on its own, or null when the value is not one.
     */
    public static function base(?string $raw): ?string
    {
        return self::isBase(self::normalise($raw)) ? self::normalise($raw) : null;
    }

    /**
     * Whether a value is a bare NIT number: digits only, no separators and no
     * digit of its own.
     */
    public static function isBase(?string $value): bool
    {
        return $value !== null && preg_match('/^\d{1,12}$/', $value) === 1;
    }

    /**
     * The digits of a value that already carries its own verification digit.
     */
    public static function checkDigit(?string $raw): ?string
    {
        $value = self::normalise($raw);

        return preg_match('/^\d{1,12}-(\d)$/', $value, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * The form a uniqueness comparison uses: digits, nothing else.
     *
     * Applied to a base number that has already been split, so it is here for
     * the search path, where a person types whatever they have in front of them.
     */
    public static function normalise(?string $raw): string
    {
        return self::split($raw)['tax_id'];
    }

    /**
     * The two LIKE patterns a search over the NIT needs.
     *
     * `number` matches the stored base number, so a person can type the number
     * with or without separators and find it. `combined` matches the number and
     * its digit joined by a hyphen, which is what somebody typing `900.123.456-3`
     * means. Both are escaped, because a person typing `%` is typing a character.
     *
     * @return array{number: string, combined: string}
     */
    public static function searchPatterns(?string $raw): array
    {
        $parts = self::split($raw);
        $number = $parts['tax_id'];

        if ($number === '') {
            return ['number' => '', 'combined' => ''];
        }

        $escaped = self::escapeWildcards($number);

        return [
            'number' => '%'.$escaped.'%',
            'combined' => $parts['verification_digit'] === null
                ? '%'.$escaped.'%'
                : '%'.$escaped.'-'.$parts['verification_digit'].'%',
        ];
    }

    /**
     * Escape the characters PostgreSQL would read as wildcards.
     *
     * `\` has to go first, or the escaping of the others would double it.
     */
    public static function escapeWildcards(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * The form shown to a person: `900.123.456-3`, or the base alone when there is
     * no digit to show.
     */
    public static function forDisplay(?string $base, ?string $verificationDigit): string
    {
        $number = trim((string) $base);

        if ($number === '') {
            return '';
        }

        $digit = trim((string) $verificationDigit);

        if (strlen($number) >= 5) {
            $number = (string) preg_replace('/(\d{3})(?=\d)/', '$1.', $number);
        }

        return $digit === '' ? $number : $number.'-'.$digit;
    }
}

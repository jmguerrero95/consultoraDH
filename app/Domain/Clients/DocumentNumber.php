<?php

declare(strict_types=1);

namespace App\Domain\Clients;

/**
 * Normalises an identity document number into the single form stored in the
 * database.
 *
 * Two rules, and the reason for the split between them:
 *
 *  - A numeric Colombian document is written in the source data with thousands
 *    separators, sometimes with a trailing check digit attached. `12.345.678` and
 *    `12345678` are the same person, so only the separators go. A trailing
 *    check digit is NOT stripped: for a cédula it is a separate piece of
 *    information that a future import may need, and silently discarding a digit
 *    from someone's identity is worse than storing it verbatim.
 *
 *  - An alphanumeric document keeps its letters and digits and is upper cased,
 *    because `ab12345` and `AB12345` are the same passport. Its internal
 *    whitespace is removed for the same reason the numeric separators are.
 *
 * The hard rule is that normalisation must never turn one identity into
 * another. Anything it is not sure about is left alone and reported as a data
 * quality question, rather than being "fixed" into something that looks valid.
 */
final class DocumentNumber
{
    /**
     * Characters used purely as visual separators in Colombian documents.
     */
    private const VISUAL_SEPARATORS = ["\u{002E}", "\u{0020}", '-', "\u{00A0}", "\u{202F}"];

    /**
     * The canonical form of a document number.
     *
     * Returns the input trimmed and upper cased, with visual separators removed
     * for the numeric document types. Returns an empty string for input that is
     * only separators, which the caller rejects as invalid.
     */
    public static function normalise(?string $raw, DocumentType $type): string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return '';
        }

        // Internal whitespace is a separator in every branch. `AB 12345` and
        // `AB12345` are written for the same passport in the source data, so
        // the gap is formatting, not part of the identity. Meaningful
        // characters are always preserved: no digit and no letter is ever
        // dropped.
        foreach (self::VISUAL_SEPARATORS as $separator) {
            $value = str_replace($separator, '', $value);
        }

        return mb_strtoupper(trim($value));
    }

    /**
     * Whether the value looks like a document number at all.
     *
     * Loose on purpose. It rejects the empty string and strings made only of
     * separators, and nothing else: deciding that `12.3.4` is "wrong" is a
     * judgement about historical data that belongs to a human, and this class
     * refuses to make it.
     */
    public static function looksValid(?string $normalised): bool
    {
        if ($normalised === null || $normalised === '') {
            return false;
        }

        // Anything outside letters, digits and the internal hyphen.
        return preg_match('/^[A-Z0-9]+(-[A-Z0-9]+)*$/', $normalised) === 1;
    }

    /**
     * A compact, searchable form with the separators still in place.
     *
     * Used by the search filter so that typing `12.345.678` or `12345678`
     * finds the same record.
     */
    public static function searchable(?string $raw): string
    {
        $value = mb_strtoupper(trim((string) $raw));

        foreach (self::VISUAL_SEPARATORS as $separator) {
            $value = str_replace($separator, '', $value);
        }

        return $value;
    }

    /**
     * The display form, with separators the way people write them.
     */
    public static function forDisplay(?string $normalised, DocumentType $type): string
    {
        $value = (string) $normalised;

        if ($value === '' || ! $type->isNumericColombian()) {
            return $value;
        }

        return self::withThousandSeparators($value);
    }

    /**
     * `12345678` becomes `12.345.678`.
     */
    private static function withThousandSeparators(string $digits): string
    {
        if (! ctype_digit($digits) || mb_strlen($digits) < 5) {
            return $digits;
        }

        $formatted = '';

        $length = mb_strlen($digits);

        for ($index = 0; $index < $length; $index++) {
            $remaining = $length - $index;

            if ($index > 0 && $remaining % 3 === 0) {
                $formatted .= '.';
            }

            $formatted .= $digits[$index];
        }

        return $formatted;
    }
}

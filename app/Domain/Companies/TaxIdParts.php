<?php

declare(strict_types=1);

namespace App\Domain\Companies;

/**
 * Reads a NIT out of a request, whichever way the caller wrote it.
 *
 * A person types `900.123.456-3`. An importer may hold the number and the digit
 * in two columns. The interface may send both. All three mean the same company,
 * and the two stored values have to come out of them the same way every time.
 *
 * The rules, in order:
 *
 *  - a `tax_id` carrying a hyphen is split, and its digit wins over a separately
 *    supplied `verification_digit`, because it is the one that came with the
 *    number;
 *  - a `tax_id` without a hyphen is taken as the bare number, and a separately
 *    supplied digit is kept;
 *  - **nothing is calculated.** When no digit arrives, the column is left empty
 *    and the data quality layer reports the absence, which is the honest state of
 *    an unknown digit. When a digit does arrive it is stored exactly as it came,
 *    including one that disagrees with the number;
 *  - an empty string means "no NIT" and becomes NULL, so the partial unique index
 *    does not treat every NIT-less company as a duplicate of the first.
 *
 * @phpstan-type Attributes array<string, mixed>
 */
final class TaxIdParts
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: string|null, 1: string|null} the number and the digit
     */
    public static function from(array $attributes): array
    {
        $raw = $attributes['tax_id'] ?? null;
        $raw = is_string($raw) ? $raw : null;

        $separate = $attributes['verification_digit'] ?? null;
        $separate = is_string($separate) && trim($separate) !== '' ? trim($separate) : null;

        if ($raw === null || trim($raw) === '') {
            // A digit on its own identifies nothing, so it is not kept either.
            return [null, null];
        }

        $parts = TaxId::split($raw);
        $number = $parts['tax_id'] === '' ? null : $parts['tax_id'];
        $inside = $parts['verification_digit'];

        return [$number, $inside ?? $separate];
    }
}

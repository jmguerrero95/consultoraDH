<?php

declare(strict_types=1);

namespace App\Domain\Companies;

/**
 * Reads a NIT out of a request, whichever way the caller wrote it, and refuses it
 * when the two spellings contradict each other.
 *
 * A person types `900.123.456-3`. An importer may hold the number and the digit in
 * two columns. The interface may send both. All three mean the same company, and
 * the two stored values have to come out of them the same way every time.
 *
 * The rules, in order:
 *
 *  - a `tax_id` carrying a hyphen is split into a number and a digit;
 *  - a `tax_id` without a hyphen is the bare number, and a separately supplied
 *    digit is used;
 *  - **two different digits in one request are refused.** When the digit inside the
 *    number and the separately supplied digit disagree, the request is rejected
 *    with `ConflictingVerificationDigit` instead of one of them being preferred.
 *    This is the behaviour `TaxIdUpdate` already had for an edit, and create has
 *    the same obligation: two answers to the same question is not a decision this
 *    system gets to make, and it must not make it differently depending on whether
 *    the company already exists;
 *  - **nothing is calculated.** When no digit arrives, the column is left empty and
 *    the data quality layer reports the absence, which is the honest state of an
 *    unknown digit. When a digit does arrive it is stored exactly as it came;
 *  - an empty string means "no NIT" and becomes NULL, so the partial unique index
 *    does not treat every NIT-less company as a duplicate of the first.
 *
 * Parsing goes through `TaxIdSyntax`, the strict parser, not through the tolerant
 * `TaxId::split()`. By the time a request reaches here the value has been validated,
 * so the strict parser cannot fail; it is used anyway so that nothing between the
 * request and the column depends on a parser that quietly repairs its input.
 *
 * @phpstan-type Attributes array<string, mixed>
 */
final class TaxIdParts
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{tax_id: string|null, verification_digit: string|null}
     *
     * @throws ConflictingVerificationDigit when the two spellings disagree
     */
    public static function from(array $attributes): array
    {
        $raw = $attributes['tax_id'] ?? null;
        $raw = is_string($raw) ? trim($raw) : '';

        $separate = $attributes['verification_digit'] ?? null;
        $separate = is_string($separate) && trim($separate) !== '' ? trim($separate) : null;

        if ($raw === '') {
            // A digit on its own identifies nothing, so it is not kept either.
            return ['tax_id' => null, 'verification_digit' => null];
        }

        $parts = TaxIdSyntax::parse($raw) ?? TaxId::split($raw);
        $number = $parts['tax_id'] === '' ? null : $parts['tax_id'];
        $inside = $parts['verification_digit'];

        if ($inside !== null && $separate !== null && $inside !== $separate) {
            throw ConflictingVerificationDigit::between($inside, $separate);
        }

        return [
            'tax_id' => $number,
            'verification_digit' => $inside ?? $separate,
        ];
    }
}

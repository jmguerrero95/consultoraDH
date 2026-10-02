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
 * Parsing goes through `TaxIdSyntax`, the strict parser, and never through the
 * tolerant `TaxId::split()`.
 *
 * There is no fallback here, and that is the point of this revision. A fallback
 * exists for a caller that has not validated its input, which was the reasoning when
 * this was written: "by the time a request arrives the value has been validated, so
 * the strict parser cannot fail". True of a form request and of nothing else. The
 * importer, the assistant and the maintenance commands all write through these same
 * services without passing through a form request, and for them the fallback would
 * have silently repaired a malformed value into a different, valid-looking NIT.
 * Strict through the interface and permissive through an importer, on one code path,
 * is the defect; so a value that is not a NIT raises `InvalidTaxId` here and the
 * caller is told which value was refused.
 *
 * `TaxId::split()` remains the parser for search, display and inspecting legacy
 * rows. It is not authoritative for a write.
 *
 * @phpstan-type Attributes array<string, mixed>
 */
final class TaxIdParts
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{tax_id: string|null, verification_digit: string|null}
     *
     * @throws InvalidTaxId when a value that is present is not a NIT
     * @throws ConflictingVerificationDigit when the two spellings disagree
     */
    public static function from(array $attributes): array
    {
        $raw = $attributes['tax_id'] ?? null;
        $raw = is_string($raw) ? trim($raw) : '';

        $separate = $attributes['verification_digit'] ?? null;
        $separate = is_string($separate) && trim($separate) !== '' ? trim($separate) : null;

        if ($raw === '') {
            // A digit on its own identifies nothing. On create that is an error the
            // operator needs to see, because a supplied field was silently dropped and
            // the response reported success. On update it is not: correcting the digit
            // of a company that already has a NIT is exactly what this field is for,
            // and the caller is decided by `forUpdate()` below.
            return ['tax_id' => null, 'verification_digit' => null];
        }

        $parts = TaxIdSyntax::parse($raw);

        if ($parts === null) {
            // Refused, not repaired. Nothing downstream can tell a repaired value from
            // the one that was sent, which is why repairing it here would be worse
            // than failing.
            throw InvalidTaxId::malformed($raw);
        }

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

    /**
     * A verification digit supplied with no NIT to attach it to, on a create.
     *
     * Returning NULL for both columns, as this class used to do, threw the digit away
     * without a word: the request succeeded, the response reported success, and a
     * field the operator filled in was gone. A digit identifies nothing on its own,
     * so the pair is a request that cannot be answered rather than a request to store
     * nothing.
     *
     * @throws InvalidTaxId
     */
    public static function refuseOrphanDigit(?string $digit): void
    {
        if ($digit === null || trim($digit) === '') {
            return;
        }

        throw InvalidTaxId::withoutBase(trim($digit));
    }
}

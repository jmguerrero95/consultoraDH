<?php

declare(strict_types=1);

namespace App\Domain\Companies;

use App\Models\Company;

/**
 * Works out what an update means when it carries a NIT, a digit, or both.
 *
 * The two values are stored apart, and that is what makes this necessary. A request
 * that sends only the base number is ambiguous: is the caller clearing the digit,
 * or editing the number and not thinking about the digit?
 *
 * Answering "clear it" was a real bug: the edit form loaded the bare number, sent
 * it back with the phone number, and the stored digit was overwritten with null.
 * Editing a company's telephone number silently erased part of its identity.
 *
 * So the answer depends on what changed:
 *
 *   nothing about the tax identity was sent   both are preserved
 *   only the digit was sent                   only the digit changes
 *   the number, unchanged, with no digit      the number and the stored digit are kept
 *   the number, changed, with no digit        the number changes and the digit becomes
 *                                             null, because it belonged to the old one
 *   an inline digit and a separate digit      refused when they disagree
 *
 * The last case is the one that must never be resolved automatically: two
 * different answers to the same question is not a decision this system gets to make.
 */
final class TaxIdUpdate
{
    /**
     * @return array{tax_id: string|null, verification_digit: string|null}
     */
    public static function resolve(Company $company, array $attributes): array
    {
        $sendsTaxId = array_key_exists('tax_id', $attributes);
        $sendsDigit = array_key_exists('verification_digit', $attributes);

        if (! $sendsTaxId && ! $sendsDigit) {
            // Nothing about the tax identity was in the request: preserve both.
            return [
                'tax_id' => $company->tax_id,
                'verification_digit' => $company->verification_digit,
            ];
        }

        $number = TaxIdParts::from($attributes)['tax_id'];

        // `array_key_exists` rather than `??` for the separate digit, because an
        // explicit null is a statement ("this value is empty") and must not be read
        // as the field being absent.
        $inside = self::digitInside($attributes['tax_id'] ?? null);
        $separate = array_key_exists('verification_digit', $attributes)
            ? self::digitSeparate($attributes['verification_digit'])
            : null;

        if ($inside !== null && $separate !== null && $inside !== $separate) {
            throw ConflictingVerificationDigit::between($inside, $separate);
        }

        $digit = $inside ?? $separate;

        if (! $sendsTaxId) {
            // Only the digit: the number stays as it is, whatever the form sent.
            return ['tax_id' => $company->tax_id, 'verification_digit' => $digit];
        }

        if ($number === $company->tax_id) {
            // The same number. Whether the stored digit survives depends on whether
            // the request mentioned it:
            //
            //   not mentioned      it is kept, so editing the telephone number of a
            //                      company cannot erase part of its identity;
            //   mentioned as null  it is cleared, because the operator said so.
            return [
                'tax_id' => $number,
                'verification_digit' => $digit ?? ($sendsDigit ? null : $company->verification_digit),
            ];
        }

        // A different number. The stored digit described the old one, and the new
        // digit is genuinely unknown unless the caller supplied it.
        return ['tax_id' => $number, 'verification_digit' => $digit];
    }

    /**
     * The digit written inside the number, if there is one.
     */
    private static function digitInside(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return TaxId::split($raw)['verification_digit'];
    }

    /**
     * The digit sent in its own field, if there is one.
     *
     * An explicit null clears it, which is how a caller says "this value is empty"
     * rather than "I did not mean to mention it".
     */
    private static function digitSeparate(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return trim($raw);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Companies;

/**
 * A value that is not a NIT at all, reaching the write domain.
 *
 * The strict grammar lives in `TaxIdSyntax`, and the HTTP boundary enforces it
 * before anything is written. This exception exists because that enforcement is not
 * the only way in.
 *
 * The next callers will not be browsers. An importer reading a spreadsheet, an
 * assistant building a record from a conversation, a maintenance command: all of
 * them call the same application services, and none of them passes through a form
 * request. When they do not, validation is not a step that happens, so the domain
 * has to refuse a malformed NIT itself rather than assume somebody already did.
 *
 * The alternative was a fallback to `TaxId::split()`, the tolerant parser, which
 * repairs `-900123456-3` and `900123456--` into a valid-looking number. With the
 * fallback, the domain's strictness depended entirely on the caller having been
 * careful: correct through the interface, permissive through a future importer, on
 * the same code path. That is a defect waiting for the importer.
 */
final class InvalidTaxId extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function malformed(string $raw): self
    {
        return new self(sprintf(
            'El NIT «%s» no tiene un formato válido. Se admiten hasta 12 dígitos, '
            .'con el dígito de verificación separado por un único guion '
            .'(por ejemplo 900123456-3).',
            $raw,
        ));
    }

    public static function withoutBase(string $digit): self
    {
        return new self(sprintf(
            'Se envió el dígito de verificación «%s» sin el NIT al que pertenece. '
            .'Un dígito de verificación solo no identifica una empresa.',
            $digit,
        ));
    }
}

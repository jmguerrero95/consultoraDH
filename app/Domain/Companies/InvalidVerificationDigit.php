<?php

declare(strict_types=1);

namespace App\Domain\Companies;

/**
 * A verification digit that is not a verification digit.
 *
 * A NIT's verification digit is a single decimal digit, 0 to 9. Nothing else is a
 * digit: not two of them, not a sign, not a letter, not a number padded with a zero.
 *
 * The rule was only ever expressed as a regex on a form request, which made it true
 * for the interface and false for everything else. The importer, the assistant and
 * the maintenance commands all write through the same application services without
 * passing through a form request, so for them `verification_digit = '12'` was not
 * refused by the domain: it reached the column, and PostgreSQL either stored it or
 * failed with an error that said nothing about the field the caller got wrong.
 *
 * Refusing here means the caller is told which value was rejected, before anything
 * is written, whichever door the request came through.
 */
final class InvalidVerificationDigit extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function for(string $received): self
    {
        return new self(sprintf(
            'El dígito de verificación «%s» no es válido: debe ser NULL o un único dígito '
            .'decimal del 0 al 9 (por ejemplo 3).',
            $received,
        ));
    }
}

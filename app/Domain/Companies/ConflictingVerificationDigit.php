<?php

declare(strict_types=1);

namespace App\Domain\Companies;

use DomainException;

/**
 * A request carries two different verification digits for the same company.
 *
 * One inside the NIT and one in its own field. This is not a formatting problem to
 * be smoothed over: the two values disagree about the same fact, and the system has
 * no way to know which the operator meant. Picking one would silently decide part
 * of a company's tax identity, so the request is refused and the operator chooses.
 */
final class ConflictingVerificationDigit extends DomainException
{
    public static function between(string $inside, string $separate): self
    {
        return new self(
            'El NIT trae dos dígitos de verificación distintos: '
            ."\u{00AB}{$inside}\u{00BB} dentro del número y \u{00AB}{$separate}\u{00BB} en su propio campo. "
            .'Corrija la petición antes de guardarla.'
        );
    }
}

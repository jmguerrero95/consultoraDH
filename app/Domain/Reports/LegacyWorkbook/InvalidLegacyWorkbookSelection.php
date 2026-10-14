<?php

declare(strict_types=1);

namespace App\Domain\Reports\LegacyWorkbook;

/**
 * A selection the export cannot build, said in a sentence a person can act on.
 *
 * A dedicated exception rather than `InvalidArgumentException` so the controller can tell "you
 * asked for something that does not exist" apart from a genuine bug, and answer 422 with this
 * message instead of 500.
 */
final class InvalidLegacyWorkbookSelection extends \DomainException
{
    public static function for(string $why): self
    {
        return new self($why);
    }
}

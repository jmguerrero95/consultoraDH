<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * How an obligation came to exist.
 *
 * Two values, because there are two honest answers. `generated` is the only one
 * the system produces on its own. `manual_correction` exists so that a month which
 * was generated wrongly can be corrected without deleting and regenerating it,
 * which would destroy the payments made against it.
 *
 * An import source is deliberately absent. A04 will own reading a workbook, and
 * adding the value now would mean guessing what shape that import takes.
 */
enum ObligationSource: string
{
    case Generated = 'generated';
    case ManualCorrection = 'manual_correction';

    public function label(): string
    {
        return match ($this) {
            self::Generated => 'Generada',
            self::ManualCorrection => 'Corrección manual',
        };
    }
}

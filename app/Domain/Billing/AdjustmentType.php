<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * Why an adjustment was made.
 *
 * The type explains; the direction lives in `delta_cop`, which is signed. A
 * discount and a surcharge are the same operation pointing opposite ways, and
 * making the arithmetic depend on the type would mean every sum had to know which
 * kinds of row it was adding.
 *
 * `reversal` is not a reason a person gives, it is what the system writes when an
 * adjustment is undone. It is here because it is stored in the same column.
 */
enum AdjustmentType: string
{
    case Discount = 'discount';
    case Surcharge = 'surcharge';
    case Correction = 'correction';
    case Credit = 'credit';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Discount => 'Descuento',
            self::Surcharge => 'Recargo',
            self::Correction => 'Corrección',
            self::Credit => 'Crédito',
            self::Reversal => 'Reversión',
        };
    }

    /**
     * Whether this type is one a person chooses, as opposed to one the system
     * writes when undoing something.
     */
    public function isReversal(): bool
    {
        return $this === self::Reversal;
    }

    /**
     * Whether the sign is expected to reduce what is owed.
     *
     * Informational, and deliberately not enforced: a "discount" that increases the
     * amount may be a mistake, but refusing it here would be guessing which of the
     * two is wrong. The domain validates the arithmetic that follows, not the
     * wording.
     */
    public function usuallyReduces(): bool
    {
        return $this === self::Discount || $this === self::Credit;
    }
}

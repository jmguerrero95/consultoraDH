<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * How much of an obligation has been settled.
 *
 * Derived, never stored. There is no `status` column on `monthly_obligations`
 * because every value here is computable from the base amount, the adjustments
 * and the allocations, and a stored status would be a second answer to a question
 * that has exactly one right one.
 *
 * ## Being late is not a state
 *
 * Overdue is a separate question, and it is answered separately, because an
 * obligation can be both partly paid and late, and collapsing the two would lose
 * exactly the case that matters most: a client who paid something and then stopped.
 * That is `is_overdue`, not a fourth state.
 */
enum SettlementState: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Partial => 'Pago parcial',
            self::Paid => 'Pagada',
        };
    }

    /**
     * @param  int  $effectiveAmount  base amount plus active adjustments
     * @param  int  $paidAmount  sum of live allocations against non-voided payments
     */
    public static function for(int $effectiveAmount, int $paidAmount): self
    {
        if ($paidAmount >= $effectiveAmount && $effectiveAmount >= 0) {
            return self::Paid;
        }

        return $paidAmount > 0 ? self::Partial : self::Pending;
    }
}

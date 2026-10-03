<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * How far a payment has been matched to obligations.
 *
 * Derived from the payment amount and its live allocations. `partially_allocated`
 * is not an error: a client who pays in advance, or pays across two months at once,
 * leaves money that has not been applied yet, and that is a normal state of affairs
 * rather than a fault to be reported.
 */
enum ReconciliationState: string
{
    case Unallocated = 'unallocated';
    case PartiallyAllocated = 'partially_allocated';
    case FullyAllocated = 'fully_allocated';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Unallocated => 'Sin aplicar',
            self::PartiallyAllocated => 'Aplicado parcialmente',
            self::FullyAllocated => 'Aplicado por completo',
            self::Voided => 'Anulado',
        };
    }

    /**
     * Money that has arrived and has not been applied to an obligation.
     *
     * Stays available: an advance is not discarded and is not a second payment. It
     * is simply money whose purpose has not been chosen yet, and an operator decides
     * when.
     */
    public function isUnallocatedAmount(): bool
    {
        return $this === self::Unallocated || $this === self::PartiallyAllocated;
    }

    public static function for(int $amount, int $allocated, bool $voided): self
    {
        if ($voided) {
            return self::Voided;
        }

        return match (true) {
            $allocated <= 0 => self::Unallocated,
            $allocated >= $amount => self::FullyAllocated,
            default => self::PartiallyAllocated,
        };
    }
}

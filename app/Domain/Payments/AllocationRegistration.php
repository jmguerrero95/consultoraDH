<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\PaymentAllocation;

/**
 * What applying a payment to an obligation produced.
 *
 * The controller needs to tell a first application from the recovery of an
 * earlier one, and from a key that cannot be used again. Returning a bare
 * `PaymentAllocation` forced that distinction to be guessed from a
 * pre-transaction read, which is exactly the read a concurrent request can
 * invalidate.
 */
final readonly class AllocationRegistration
{
    private function __construct(
        public PaymentAllocation $allocation,
        public bool $created,
        public bool $conflict,
        public bool $reversed,
    ) {}

    public static function created(PaymentAllocation $allocation): self
    {
        return new self($allocation, created: true, conflict: false, reversed: false);
    }

    /**
     * The same key and the same payment, obligation and amount: the original
     * application is the answer.
     */
    public static function replayed(PaymentAllocation $allocation): self
    {
        return new self($allocation, created: false, conflict: false, reversed: false);
    }

    /**
     * The key is already used by an application with different data.
     */
    public static function conflict(PaymentAllocation $allocation): self
    {
        return new self($allocation, created: false, conflict: true, reversed: false);
    }

    /**
     * The key belongs to an application that was reversed. It cannot be reused,
     * and no second application may be created from it.
     */
    public static function reversed(PaymentAllocation $allocation): self
    {
        return new self($allocation, created: false, conflict: false, reversed: true);
    }
}

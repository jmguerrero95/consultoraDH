<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\Payment;

/**
 * What registering a payment produced.
 *
 * The controller needs to tell a first registration from the recovery of an
 * earlier one, and from a key that cannot be used again. Returning a bare
 * `Payment` forced that distinction to be guessed from a pre-transaction read,
 * which is exactly the read a concurrent request can invalidate.
 *
 * `confirm_duplicate` is deliberately absent: it is an answer given during the
 * request, not part of the payment's identity.
 */
final readonly class PaymentRegistration
{
    private function __construct(
        public Payment $payment,
        public bool $created,
        public bool $conflict,
        public bool $voided,
    ) {}

    public static function created(Payment $payment): self
    {
        return new self($payment, created: true, conflict: false, voided: false);
    }

    /**
     * The same key and the same identity: the original payment is the answer.
     */
    public static function replayed(Payment $payment): self
    {
        return new self($payment, created: false, conflict: false, voided: false);
    }

    /**
     * The key is already used by a payment with different data.
     */
    public static function conflict(Payment $payment): self
    {
        return new self($payment, created: false, conflict: true, voided: false);
    }

    /**
     * The key belongs to a voided payment. It cannot be reused, and no second
     * payment may be created from it.
     */
    public static function voided(Payment $payment): self
    {
        return new self($payment, created: false, conflict: false, voided: true);
    }
}

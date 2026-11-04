<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * What an auto-allocation execution produced.
 *
 * The controller needs to tell a first execution from a recovery, from a key that
 * belongs to another payment, and from a key whose money was voided or reversed.
 * Returning a bare `AllocationOutcome` forced that distinction to be guessed from a
 * pre-transaction read, which is exactly the read a concurrent request can invalidate.
 */
final readonly class AutoAllocationRegistration
{
    private function __construct(
        public AllocationOutcome $outcome,
        public bool $created,
        public bool $conflict,
        public bool $voided,
    ) {}

    public static function created(AllocationOutcome $outcome): self
    {
        return new self($outcome, created: true, conflict: false, voided: false);
    }

    /**
     * The same idempotency key and the same payment: the original outcome
     * is the answer. No new applications, no new events.
     */
    public static function replayed(AllocationOutcome $outcome): self
    {
        return new self($outcome, created: false, conflict: false, voided: false);
    }

    /**
     * The key is already used by an execution of a different payment.
     * Nothing was executed and nothing will be.
     */
    public static function conflict(): self
    {
        return new self(new AllocationOutcome([], 0, 0), created: false, conflict: true, voided: false);
    }

    /**
     * The key belongs to a voided payment, to an execution with reversed
     * applications, or to an incomplete result. It cannot be reused, and no
     * second execution may be created from it.
     */
    public static function voided(): self
    {
        return new self(new AllocationOutcome([], 0, 0), created: false, conflict: false, voided: true);
    }
}

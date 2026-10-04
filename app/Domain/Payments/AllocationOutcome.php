<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\PaymentAllocation;

/**
 * What applying a payment oldest-first actually did.
 *
 * Returned rather than a count, because the interesting part is usually the
 * remainder: a payment that covered everything leaves something over, and that
 * something is an advance that stays available rather than an error.
 */
final readonly class AllocationOutcome
{
    /**
     * @param  list<PaymentAllocation>  $allocations
     */
    public function __construct(
        public array $allocations,
        public int $remainingAvailable,
        public int $appliedCount,
    ) {}

    /**
     * Money received that no obligation has claimed yet.
     *
     * Named for what it is rather than exposed as a bare constructor property, because
     * `remainingAvailable` could be read as either "left on the payment" or "left on the
     * last debt". This is the payment.
     */
    public function unallocatedAmount(): int
    {
        return $this->remainingAvailable;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'applied_count' => $this->appliedCount,
            'allocations' => array_map(
                fn (PaymentAllocation $allocation): array => [
                    'allocation_id' => $allocation->id,
                    'obligation_id' => $allocation->obligation_id,
                    'amount_cop' => $allocation->amount_cop,
                ],
                $this->allocations,
            ),
            'unallocated_amount_cop' => $this->remainingAvailable,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What generation actually did.
 *
 * Returned rather than a bare count, because "nothing was created" and "twelve were
 * created" are both legitimate outcomes of the same request and an operator needs to tell
 * them apart without counting rows afterwards.
 *
 * ## The amount is what was written
 *
 * `totalAmountCop` is the sum of the rows this execution inserted, **not** the month's
 * resolved total. The earlier version passed the preview's total through, which included
 * obligations that already existed and were deliberately not touched — so a generation
 * that created nothing could report a large non-zero amount, and the number a caller
 * would call "created" disagreed with `created`.
 */
final readonly class GenerationResult
{
    public function __construct(
        public int $periodId,
        public string $periodKey,
        public int $created,
        public int $considered,
        /** How many candidates already had an obligation and were left untouched. */
        public int $existingCount,
        public int $totalAmountCop,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period_id' => $this->periodId,
            'period_key' => $this->periodKey,
            'created' => $this->created,
            // Kept as its own name rather than folded into `considered`, because
            // "considered 40" invites the reading that forty rows were examined and forty
            // written.
            'existing' => $this->existingCount,
            'considered' => $this->considered,
            'total_amount_cop' => $this->totalAmountCop,
            'nothing_to_do' => $this->created === 0,
        ];
    }
}

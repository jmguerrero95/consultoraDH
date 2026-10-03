<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * What generation actually did.
 *
 * Returned rather than a bare count, because "nothing was created" and "twelve were
 * created" are both legitimate outcomes of the same request and an operator needs to
 * tell them apart without counting rows afterwards.
 */
final readonly class GenerationResult
{
    public function __construct(
        public int $periodId,
        public string $periodKey,
        public int $created,
        public int $considered,
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
            'considered' => $this->considered,
            'total_amount_cop' => $this->totalAmountCop,
            'nothing_to_do' => $this->created === 0,
        ];
    }
}

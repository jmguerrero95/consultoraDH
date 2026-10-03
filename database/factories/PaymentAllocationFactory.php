<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MonthlyObligation;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'obligation_id' => MonthlyObligation::factory(),
            'amount_cop' => 100000,
            'created_by' => null,
        ];
    }

    public function reversed(): self
    {
        return $this->state(fn (): array => [
            'reversed_at' => now(),
            'reversal_reason' => 'Revertida en la prueba',
        ]);
    }
}

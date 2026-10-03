<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\AdjustmentType;
use App\Models\MonthlyObligation;
use App\Models\ObligationAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObligationAdjustment>
 */
class ObligationAdjustmentFactory extends Factory
{
    protected $model = ObligationAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'obligation_id' => MonthlyObligation::factory(),
            'type' => AdjustmentType::Discount->value,
            'delta_cop' => -10000,
            'reason' => 'Ajuste de prueba',
            'created_by' => null,
        ];
    }

    public function type(AdjustmentType $type, int $deltaCop): self
    {
        return $this->state(fn (): array => [
            'type' => $type->value,
            'delta_cop' => $deltaCop,
        ]);
    }
}

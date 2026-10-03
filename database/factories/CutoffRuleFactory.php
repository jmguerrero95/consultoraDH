<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\CutoffMonthOffset;
use App\Domain\Billing\CutoffScope;
use App\Models\CutoffRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CutoffRule>
 */
class CutoffRuleFactory extends Factory
{
    protected $model = CutoffRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => CutoffScope::General->value,
            'company_id' => null,
            'client_id' => null,
            'effective_month' => now()->startOfMonth()->toDateString(),
            'cutoff_day' => $this->faker->numberBetween(1, 28),
            'month_offset' => CutoffMonthOffset::SameMonth->value,
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function general(int $day, CutoffMonthOffset $offset, string $effectiveMonth = '2026-01'): self
    {
        return $this->state(fn (): array => [
            'scope' => CutoffScope::General->value,
            'company_id' => null,
            'client_id' => null,
            'cutoff_day' => $day,
            'month_offset' => $offset->value,
            'effective_month' => $effectiveMonth.'-01',
        ]);
    }
}

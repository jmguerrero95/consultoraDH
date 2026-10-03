<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Domain\Periods\PeriodStatus;
use App\Models\MonthlyPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<MonthlyPeriod>
 */
class MonthlyPeriodFactory extends Factory
{
    protected $model = MonthlyPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A month derived from the current date by default, because a period without
        // a month cannot be built. Tests that care about a specific month pass one.
        $month = MonthValue::fromFirstDay(
            Carbon::instance($this->faker->dateTimeBetween('-8 months', '+1 month'))
        );

        return [
            'period_month' => $month->startsOn(),
            'status' => PeriodStatus::Open->value,
            'opened_at' => Carbon::now()->subMonth(),
            'opened_by' => null,
        ];
    }

    public function forMonth(string $key): self
    {
        return $this->state(fn (): array => [
            'period_month' => MonthValue::fromKey($key)->startsOn(),
        ]);
    }

    public function open(): self
    {
        return $this->state(fn (): array => [
            'status' => PeriodStatus::Open->value,
            'closed_at' => null,
            'closed_by' => null,
        ]);
    }

    public function closed(): self
    {
        return $this->state(fn (): array => [
            'status' => PeriodStatus::Closed->value,
            'closed_at' => Carbon::now(),
            'closed_by' => null,
        ]);
    }

    /** A period generation has already been run for, produced anything or not. */
    public function generated(): self
    {
        return $this->state(fn (): array => [
            'generation_performed_at' => Carbon::now(),
        ]);
    }
}

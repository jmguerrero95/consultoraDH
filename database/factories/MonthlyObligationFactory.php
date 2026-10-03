<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\ObligationSource;
use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Domain\Periods\PeriodStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<MonthlyObligation>
 */
class MonthlyObligationFactory extends Factory
{
    protected $model = MonthlyObligation::class;

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Lazy: the factory value is only resolved when nothing overrides it, so
            // `forPeriod()` does not create a second, unused month. An eager
            // `MonthlyPeriod::factory()->create()` here would collide with the
            // unique index on `period_month` every time a test pinned a period.
            'period_id' => MonthlyPeriod::factory()->forMonth(
                Carbon::now()->subMonths(random_int(1, 6))->format('Y-m')
            ),
            'client_id' => Client::factory(),
            'company_id' => Company::factory(),
            'client_company_assignment_id' => null,
            'rate_id' => null,
            'cutoff_rule_id' => null,
            // Whole pesos, and always a realistic magnitude. A factory that produced
            // a random int would produce obligations of 3 pesos and 4 billion pesos,
            // and a test that formatted one would look fine while asserting nothing.
            'base_amount_cop' => $this->faker->randomElement([160000, 235000, 280000, 1250000, 1800000]),
            'due_on' => null,
            'generated_at' => Carbon::now(),
            'generated_by' => null,
            'source' => ObligationSource::Generated->value,
        ];
    }

    /**
     * A due date on the tenth of the month after the period, which is what a cutoff
     * of the tenth with a one-month offset produces.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (MonthlyObligation $obligation): void {
            if ($obligation->due_on === null && $obligation->period !== null) {
                $obligation->due_on = $obligation->period->period_month
                    ->copy()
                    ->addMonthNoOverflow()
                    ->day(10);
            }
        });
    }

    public function forPeriod(MonthlyPeriod $period): self
    {
        return $this->state(fn (): array => ['period_id' => $period->id]);
    }

    /**
     * An obligation for a given month, creating the period if it does not exist.
     */
    public function forMonth(string $key): self
    {
        return $this->state(function () use ($key): array {
            $month = MonthValue::fromKey($key);

            $period = MonthlyPeriod::query()->firstOrCreate(
                ['period_month' => $month->startsOn()],
                ['status' => PeriodStatus::Open->value, 'opened_at' => Carbon::now()],
            );

            return [
                'period_id' => $period->id,
                'due_on' => $month->startsOn()->copy()->addMonthNoOverflow()->day(10),
            ];
        });
    }

    public function dueOn(Carbon|string $date): self
    {
        return $this->state(fn (): array => [
            'due_on' => $date instanceof Carbon ? $date->format('Y-m-d') : $date,
        ]);
    }

    public function amount(int $cop): self
    {
        return $this->state(fn (): array => ['base_amount_cop' => $cop]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Billing\CutoffMonthOffset;
use App\Domain\Billing\CutoffScope;
use App\Domain\Billing\ObligationSource;
use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Domain\Periods\PeriodStatus;
use App\Models\Client;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\CutoffRule;
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
            // Filled in by `configure()`. Null here means "not supplied by the caller",
            // not "stored as null": a generated row must quote the configuration that
            // produced it, and A03-R1's CHECK enforces that at the database.
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
     *
     * This is also where a `generated` row is given the configuration it quotes.
     *
     * A03-R1 made `rate_id` and `cutoff_rule_id` mandatory for `source = 'generated'`,
     * which means a factory that produced a generated row without them was building
     * something the domain now refuses to create. That refusal is correct — an amount
     * with no rate behind it cannot be explained — so the factory has to supply the
     * evidence rather than the constraint being loosened.
     *
     * `firstOrCreate` rather than `create`, because the uniqueness is on
     * `(client, company, effective_month)` for a rate and on the scope identifiers plus
     * `effective_month` for a cutoff: a test building several obligations for the same
     * employer and month must reuse one decision rather than collide with itself.
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

            // `source` is cast to the enum, so this is an enum comparison. Comparing
            // against `->value` silently skipped the whole block, which is exactly the
            // kind of quiet no-op that leaves a constraint failing with no explanation.
            if ($obligation->source !== ObligationSource::Generated) {
                return;
            }

            $effectiveMonth = ($obligation->period?->period_month ?? Carbon::now())->copy()->startOfMonth();

            if ($obligation->rate_id === null) {
                $rate = ClientCompanyRate::query()->firstOrCreate(
                    [
                        'client_id' => $obligation->client_id,
                        'company_id' => $obligation->company_id,
                        'effective_month' => $effectiveMonth->toDateString(),
                    ],
                    [
                        'amount_cop' => $obligation->base_amount_cop > 0
                            ? $obligation->base_amount_cop
                            : 235000,
                    ],
                );

                $obligation->rate_id = $rate->id;
            }

            if ($obligation->cutoff_rule_id === null) {
                $rule = CutoffRule::query()->firstOrCreate(
                    [
                        'scope' => CutoffScope::General->value,
                        'company_id' => null,
                        'client_id' => null,
                        'effective_month' => $effectiveMonth->toDateString(),
                    ],
                    [
                        'cutoff_day' => 10,
                        'month_offset' => CutoffMonthOffset::FollowingMonth->value,
                    ],
                );

                $obligation->cutoff_rule_id = $rule->id;
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

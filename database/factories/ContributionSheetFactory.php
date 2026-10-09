<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\PlanillaOperator;
use App\Models\Company;
use App\Models\ContributionSheet;
use App\Models\MonthlyPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContributionSheet>
 *
 * A05.2. The digest is a plausible 64-character hex string because the column is `char(64)` and
 * `contribution_sheets_source_digest` is checked like every other fingerprint in this repository.
 */
final class ContributionSheetFactory extends Factory
{
    protected $model = ContributionSheet::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'monthly_period_id' => MonthlyPeriod::factory(),
            'company_id' => Company::factory(),
            'operator' => PlanillaOperator::Simple->value,
            'operator_other_name' => null,
            'sheet_number' => null,
            'reference' => null,
            'status' => ContributionSheetStatus::Draft->value,
            'notes' => null,
            'created_by' => User::factory(),
            'source_digest' => $this->faker->sha256(),
            'revision' => 1,
        ];
    }

    public function status(ContributionSheetStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }
}

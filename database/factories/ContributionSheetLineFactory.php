<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\ContributionSheet;
use App\Models\ContributionSheetLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContributionSheetLine>
 */
final class ContributionSheetLineFactory extends Factory
{
    protected $model = ContributionSheetLine::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'contribution_sheet_id' => ContributionSheet::factory(),
            'client_id' => Client::factory(),
            'client_company_assignment_id' => ClientCompanyAssignment::factory(),
            'document_type' => 'CC',
            'document_number' => $this->faker->numerify('##########'),
            'client_name' => $this->faker->name(),
            'company_tax_id' => '900123456',
            'company_name' => 'ANDINA S.A.S.',
            'relationship_started_on' => now()->subYear()->toDateString(),
            'relationship_started_on_precision' => 'day',
            'relationship_ended_on' => null,
            'relationship_ended_on_precision' => null,
            'eps_name' => 'SALUD TOTAL',
            'afp_name' => 'AFP PENSIONES',
            'arl_name' => 'ARL SURA',
            'ccf_name' => 'COMFORT',
            'arl_risk_class' => '1',
            'job_title' => 'Analista',
            'liquidated_amount_cop' => 1_000_000,
            'included' => true,
            'exclusion_reason' => null,
            'source_evidence' => null,
        ];
    }

    public function withoutAmount(): static
    {
        return $this->state(fn (): array => ['liquidated_amount_cop' => null]);
    }

    public function excluded(string $reason = 'Excluido por el responsable'): static
    {
        return $this->state(fn (): array => ['included' => false, 'exclusion_reason' => $reason]);
    }
}

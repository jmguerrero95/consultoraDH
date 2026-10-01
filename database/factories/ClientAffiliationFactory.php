<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Affiliations\ArlRiskClass;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\SocialSecurityEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientAffiliation>
 */
final class ClientAffiliationFactory extends Factory
{
    protected $model = ClientAffiliation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'social_security_entity_id' => SocialSecurityEntity::factory(),
            'client_company_assignment_id' => null,
            'type' => SocialSecurityEntityType::Eps,
            'started_on' => now()->subYears(3)->startOfYear()->toDateString(),
            'ended_on' => null,
            'arl_risk_class' => null,
            'notes' => null,
        ];
    }

    public function arl(ArlRiskClass $level = ArlRiskClass::LevelThree): static
    {
        return $this->state(fn (): array => [
            'type' => SocialSecurityEntityType::Arl,
            'arl_risk_class' => $level,
        ]);
    }

    public function closed(string $endedOn): static
    {
        return $this->state(fn (): array => ['ended_on' => $endedOn]);
    }
}

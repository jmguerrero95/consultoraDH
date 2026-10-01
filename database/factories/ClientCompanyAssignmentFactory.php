<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientCompanyAssignment>
 */
final class ClientCompanyAssignmentFactory extends Factory
{
    protected $model = ClientCompanyAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'company_id' => Company::factory(),
            // Far enough back that any realistic close date in a test is valid.
            'started_on' => now()->subYears(3)->startOfYear()->toDateString(),
            'ended_on' => null,
            'job_title' => $this->faker->jobTitle(),
            'notes' => null,
            'parallel_authorized_at' => null,
            'parallel_reason' => null,
        ];
    }

    /**
     * An already closed relationship.
     */
    public function closed(string $endedOn): static
    {
        return $this->state(fn (): array => ['ended_on' => $endedOn]);
    }

    /**
     * A second open relationship, authorised with a reason.
     */
    public function parallel(string $reason = 'Trabaja en ambas empresas'): static
    {
        return $this->state(fn (): array => [
            'parallel_authorized_at' => now(),
            'parallel_reason' => $reason,
        ]);
    }
}

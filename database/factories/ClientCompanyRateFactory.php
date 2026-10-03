<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientCompanyRate>
 */
class ClientCompanyRateFactory extends Factory
{
    protected $model = ClientCompanyRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'company_id' => Company::factory(),
            'effective_month' => now()->startOfMonth()->toDateString(),
            'amount_cop' => $this->faker->randomElement([160000, 235000, 280000]),
            'notes' => null,
            'created_by' => null,
        ];
    }
}

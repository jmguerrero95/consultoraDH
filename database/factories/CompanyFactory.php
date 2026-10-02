<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\RecordStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
final class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_name' => rtrim($this->faker->company(), '.').' S.A.S.',
            'trade_name' => $this->faker->company(),
            // Unique so a batch of test companies never collide on the partial
            // unique index.
            'tax_id' => $this->faker->unique()->numerify('#########'),
            'verification_digit' => '1',
            'email' => $this->faker->unique()->companyEmail(),
            'phone' => $this->faker->numerify('2########'),
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'department' => $this->faker->randomElement([
                'Antioquia', 'Atlántico', 'Bogotá D.C.', 'Cundinamarca', 'Santander', 'Valle del Cauca',
            ]),
            'status' => RecordStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => RecordStatus::Inactive]);
    }

    public function withoutTaxId(): static
    {
        return $this->state(fn (): array => ['tax_id' => null]);
    }
}

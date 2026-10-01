<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\RecordStatus;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 *
 * Synthetic data only. The names and documents are invented, never taken from a
 * real person, so a test run cannot put personal data into a log or a screenshot.
 * The document number is derived from the model key so that two clients in the
 * same test never collide.
 */
final class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $serial = $this->faker->unique()->numberBetween(1_000_000, 9_999_999);

        return [
            'document_type' => 'CC',
            'document_number' => (string) $serial,
            'first_names' => $this->faker->firstName(),
            'last_names' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->numerify('3#########'),
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'department' => $this->faker->randomElement([
                'Antioquia', 'Atlántico', 'Bogotá D.C.', 'Bolívar', 'Boyacá',
                'Caldas', 'Cundinamarca', 'Huila', 'Magdalena', 'Nariño',
                'Norte de Santander', 'Quindío', 'Risaralda', 'Santander', 'Valle del Cauca',
            ]),
            'status' => RecordStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => RecordStatus::Inactive]);
    }

    public function withDocument(string $type, string $number): static
    {
        return $this->state(fn (): array => [
            'document_type' => $type,
            'document_number' => $number,
        ]);
    }
}

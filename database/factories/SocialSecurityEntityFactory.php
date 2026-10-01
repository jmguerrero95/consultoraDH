<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Shared\RecordStatus;
use App\Models\SocialSecurityEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialSecurityEntity>
 *
 * The names are invented on purpose. A real catalogue is loaded by an
 * administrator from an official source, and a factory that produced plausible
 * looking EPS names would put made up organisations into test data where they
 * could be mistaken for real ones.
 */
final class SocialSecurityEntityFactory extends Factory
{
    protected $model = SocialSecurityEntity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => SocialSecurityEntityType::Eps,
            'name' => 'Entidad de Prueba '.$this->faker->unique()->numberBetween(1, 999_999),
            'code' => strtoupper($this->faker->unique()->bothify('E##?')),
            'tax_id' => null,
            'status' => RecordStatus::Active,
        ];
    }

    public function ofType(SocialSecurityEntityType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function arl(): static
    {
        return $this->state(fn (): array => ['type' => SocialSecurityEntityType::Arl]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => RecordStatus::Inactive]);
    }
}

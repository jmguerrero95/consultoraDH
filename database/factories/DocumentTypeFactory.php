<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentType>
 */
final class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
            'slug' => $this->faker->unique()->slug(3),
            'description' => $this->faker->sentence(),
            'retention_days' => null,
            'client_visible_default' => false,
            'active' => true,
        ];
    }
}

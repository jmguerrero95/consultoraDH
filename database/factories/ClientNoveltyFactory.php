<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Operations\NoveltyCategory;
use App\Domain\Operations\NoveltyStatus;
use App\Models\Client;
use App\Models\ClientNovelty;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientNovelty>
 */
final class ClientNoveltyFactory extends Factory
{
    protected $model = ClientNovelty::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'company_id' => null,
            'monthly_period_id' => null,
            'contribution_sheet_id' => null,
            'category' => NoveltyCategory::General,
            'title' => $this->faker->sentence(4),
            'details' => $this->faker->paragraph(),
            'status' => NoveltyStatus::Open,
            'occurred_on' => now()->toDateString(),
            'created_by' => User::factory(),
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }
}

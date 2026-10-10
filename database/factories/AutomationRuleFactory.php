<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AutomationRuleFactory extends Factory
{
    protected $model = AutomationRule::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->paragraph(),
            'active' => true,
            'trigger_type' => 'period.closed',
            'trigger_config' => [],
            'condition_config' => ['all' => []],
            'owner_user_id' => null,
            'revision' => 1,
            'activated_at' => now(),
            'next_run_at' => now()->addDay(),
            'last_run_at' => null,
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_user_id' => $user->id,
        ]);
    }
}
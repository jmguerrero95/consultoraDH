<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportQueue;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupportQueueFactory extends Factory
{
    protected $model = SupportQueue::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' Queue',
            'slug' => fake()->unique()->slug(2),
            'active' => true,
            'fallback_user_id' => null,
            'escalation_user_id' => null,
            'first_response_minutes' => 60,
            'next_response_minutes' => 120,
            'resolution_minutes' => 1440,
            'warning_minutes_before' => 15,
            'fallback_email_delay_minutes' => 30,
            'telegram_escalation_enabled' => false,
            'created_by' => null,
        ];
    }
}
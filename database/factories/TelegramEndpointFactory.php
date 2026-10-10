<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportConversation;
use App\Models\TelegramEndpoint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TelegramEndpointFactory extends Factory
{
    protected $model = TelegramEndpoint::class;

    public function definition(): array
    {
        return [
            'label' => $this->faker->words(3, true),
            'chat_id' => (string) $this->faker->numberBetween(100000000, 999999999),
            'enabled' => true,
            'event_preferences' => [],
            'created_by' => null,
        ];
    }

    /**
     * An endpoint that only ever answers for one conversation.
     */
    public function forConversation(SupportConversation $conversation): static
    {
        return $this->state(fn (array $attributes) => [
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * An endpoint subscribed to one alert type.
     */
    public function subscribedTo(string $event): static
    {
        return $this->state(fn (array $attributes) => [
            'event_preferences' => array_values(array_unique([
                ...($attributes['event_preferences'] ?? []),
                $event,
            ])),
        ]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }
}
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupportConversationFactory extends Factory
{
    protected $model = SupportConversation::class;

    public function definition(): array
    {
        return [
            'client_id' => null,
            'queue_id' => null,
            'assigned_to_user_id' => null,
            'subject' => fake()->sentence(3),
            'status' => 'waiting_staff',
            'priority' => 'normal',
            'origin_channel' => 'portal',
            'last_message_at' => now(),
            'first_response_due_at' => null,
            'next_staff_response_due_at' => null,
            'resolution_due_at' => null,
            'first_responded_at' => null,
            'resolved_at' => null,
            'resolved_by' => null,
            'closed_at' => null,
            'closed_by' => null,
            'created_by_user_id' => null,
        ];
    }
}
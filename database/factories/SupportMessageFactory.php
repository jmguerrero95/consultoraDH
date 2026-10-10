<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupportMessageFactory extends Factory
{
    protected $model = SupportMessage::class;

    public function definition(): array
    {
        return [
            'conversation_id' => null,
            'author_user_id' => null,
            'sender_kind' => 'client',
            'message_kind' => 'message',
            'channel' => 'portal',
            'body_text' => fake()->paragraph(),
            'client_visible' => true,
            'external_message_id' => null,
            'ingress_fingerprint' => null,
            'email_from' => null,
            'email_to' => null,
            'in_reply_to' => null,
        ];
    }
}
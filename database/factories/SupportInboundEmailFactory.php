<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportInboundEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A quarantined inbound message: delivered by the mail provider, not yet
 * attributable to a conversation. That is the only state the review endpoints
 * accept, so it is what this factory produces by default.
 */
class SupportInboundEmailFactory extends Factory
{
    protected $model = SupportInboundEmail::class;

    public function definition(): array
    {
        return [
            // Both columns carry a unique index, so each row needs its own value.
            'external_message_id' => '<'.uniqid('ext', true).'@mail.example.test>',
            'ingress_fingerprint' => hash('sha256', uniqid('ingress', true)),
            'from_address' => $this->faker->safeEmail(),
            'to_address' => 'soporte@consultora-dh.test',
            'subject' => $this->faker->sentence(4),
            'body_text' => $this->faker->paragraph(),
            'status' => 'quarantined',
            'reason' => 'no_correlation',
        ];
    }

    public function quarantined(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'quarantined',
            'linked_conversation_id' => null,
            'linked_by' => null,
            'linked_at' => null,
        ]);
    }
}

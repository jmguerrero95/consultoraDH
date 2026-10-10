<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An attachment owned by a conversation.
 *
 * `conversation_id` is derived from the message when one is supplied, and from an
 * explicitly given conversation otherwise, because the column is NOT NULL: an
 * attachment can be uploaded before the message that carries it exists, but it
 * can never belong to no conversation. Deriving it here keeps the factory from
 * producing rows the production schema would refuse.
 */
class SupportMessageAttachmentFactory extends Factory
{
    protected $model = SupportMessageAttachment::class;

    public function definition(): array
    {
        $conversationId = SupportConversation::factory();

        return [
            'support_message_id' => null,
            'conversation_id' => $conversationId,
            'kind' => 'file',
            'original_name' => 'documento.pdf',
            'stored_path' => 'support-attachments/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(100, 1000000),
            'sha256' => hash('sha256', fake()->randomBytes(32)),
            'uploaded_by_user_id' => null,
            'source_channel' => 'portal',
        ];
    }

    /** Attach the row to a message, taking the conversation from that message. */
    public function forMessage(SupportMessage $message): static
    {
        return $this->state(fn (array $attributes): array => [
            'support_message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
        ]);
    }
}
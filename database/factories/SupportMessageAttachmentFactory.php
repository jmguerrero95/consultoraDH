<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportMessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupportMessageAttachmentFactory extends Factory
{
    protected $model = SupportMessageAttachment::class;

    public function definition(): array
    {
        return [
            'support_message_id' => null,
            'kind' => 'file',
            'original_name' => fake()->fileExtension(),
            'stored_path' => 'support-attachments/' . fake()->uuid() . '.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(100, 1000000),
            'sha256' => hash('sha256', fake()->randomBytes(32)),
            'uploaded_by_user_id' => null,
            'source_channel' => 'portal',
        ];
    }
}
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\DocumentReviewStatus;
use App\Domain\Documents\DocumentVisibility;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientDocument>
 */
final class ClientDocumentFactory extends Factory
{
    protected $model = ClientDocument::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'document_type_id' => DocumentType::factory(),
            'document_request_id' => null,
            'title' => $this->faker->sentence(4),
            'description' => null,
            'original_name' => $this->faker->word().'.pdf',
            'stored_path' => 'documents/'.$this->faker->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => $this->faker->sha256(),
            'visibility' => DocumentVisibility::Internal,
            'review_status' => DocumentReviewStatus::Received,
            'uploaded_by_user_id' => User::factory(),
            'uploaded_via_portal' => false,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
            'retention_until' => null,
            'archived_at' => null,
        ];
    }
}

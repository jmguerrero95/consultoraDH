<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\DocumentRequestStatus;
use App\Models\Client;
use App\Models\ClientDocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientDocumentRequest>
 */
final class ClientDocumentRequestFactory extends Factory
{
    protected $model = ClientDocumentRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'document_type_id' => DocumentType::factory(),
            'title' => $this->faker->sentence(4),
            'instructions' => $this->faker->paragraph(),
            'status' => DocumentRequestStatus::Requested,
            'due_on' => now()->addWeek()->toDateString(),
            'requested_by' => User::factory(),
            'requested_at' => now(),
            'received_at' => null,
            'reviewed_at' => null,
            'approved_at' => null,
            'reviewed_by' => null,
            'decision_note' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
        ];
    }
}

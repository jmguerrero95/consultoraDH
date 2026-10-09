<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Portal\ProfileUpdateRequestStatus;
use App\Models\Client;
use App\Models\ClientProfileUpdateRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientProfileUpdateRequest>
 */
final class ClientProfileUpdateRequestFactory extends Factory
{
    protected $model = ClientProfileUpdateRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'requested_by_user_id' => User::factory(),
            'proposed_changes' => ['phone' => '3009998877'],
            'status' => ProfileUpdateRequestStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
            'applied_at' => null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client, in full.
 *
 * @mixin Client
 */
final class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Client $client */
        $client = $this->resource;

        return [
            'id' => $client->id,
            'document_type' => $client->document_type->value,
            'document_type_label' => $client->document_type->label(),
            'document_number' => $client->document_number,
            'document_label' => $client->documentLabel(),
            'first_names' => $client->first_names,
            'last_names' => $client->last_names,
            'full_name' => $client->fullName(),
            'email' => $client->email,
            'phone' => $client->phone,
            'address' => $client->address,
            'city' => $client->city,
            'department' => $client->department,
            'status' => $client->status->value,
            'status_label' => $client->status->label(),
            'created_at' => $client->created_at?->toIso8601String(),
            'updated_at' => $client->updated_at?->toIso8601String(),
        ];
    }
}

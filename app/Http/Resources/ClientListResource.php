<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client as it appears in the list.
 *
 * Deliberately narrower than the full resource. A list of two hundred clients
 * must not carry addresses and notes nobody is looking at, and the set of
 * columns on the screen is short enough that the extra payload would be pure
 * weight.
 *
 * @mixin Client
 */
final class ClientListResource extends JsonResource
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
            'document_type_short' => $client->document_type->shortLabel(),
            'document_number' => $client->document_number,
            'document_label' => $client->documentLabel(),
            'full_name' => $client->fullName(),
            'first_names' => $client->first_names,
            'last_names' => $client->last_names,
            'email' => $client->email,
            'phone' => $client->phone,
            'status' => $client->status->value,
            'status_label' => $client->status->label(),
            // Eager loaded by the controller when the list is not filtered by
            // company, so a client in three companies still costs one query.
            'companies_count' => $client->companies_count ?? null,
            'companies' => CompanySummaryResource::collection($client->relationLoaded('companyAssignments')
                ? $client->companyAssignments
                    ->whereNull('ended_on')
                    ->map(fn ($a) => $a->company)
                    ->filter()
                : collect()),
        ];
    }
}

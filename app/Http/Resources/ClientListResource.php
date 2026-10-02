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
 * ## What is not here unless the viewer may read it
 *
 * `companies` and `companies_count` are relationship information. Reading a client
 * says nothing about which companies the person works for, so a role with
 * `clients.view` and without `relationships.view` receives the identity and the
 * contact details and no employment data at all. The keys are absent rather than
 * empty: "no companies" and "not allowed to see them" are different answers, and
 * only one of them would be true.
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

        $identity = [
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
        ];

        if (! $request->user()?->can('relationships.view')) {
            return $identity;
        }

        // Eager loaded by the controller, so a client in three companies still costs
        // one query for the whole page.
        return $identity + [
            'companies_count' => $client->companies_count ?? null,
            'companies' => $client->relationLoaded('companyAssignments')
                ? CompanySummaryResource::collection(
                    $client->companyAssignments
                        ->whereNull('ended_on')
                        ->map(fn ($assignment) => $assignment->company)
                        ->filter()
                )->resolve()
                : [],
        ];
    }
}

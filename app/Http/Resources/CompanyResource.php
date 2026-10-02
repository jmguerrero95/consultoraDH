<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A company, in full.
 *
 * ## Counts are relationship information
 *
 * `active_clients_count` and `total_clients_count` say how many people are
 * employed by this company. Reading a company is not the same permission as reading
 * its workforce, so the counts appear only for a viewer who may read relationships.
 * The keys are absent rather than null when the viewer may not, so that "no clients"
 * and "not permitted to look" stay different answers.
 *
 * @mixin Company
 */
final class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Company $company */
        $company = $this->resource;

        $details = [
            'id' => $company->id,
            'legal_name' => $company->legal_name,
            'trade_name' => $company->trade_name,
            'display_name' => $company->displayName(),
            'tax_id' => $company->tax_id,
            'tax_id_label' => $company->taxIdLabel(),
            'verification_digit' => $company->verification_digit,
            'email' => $company->email,
            'phone' => $company->phone,
            'address' => $company->address,
            'city' => $company->city,
            'department' => $company->department,
            'status' => $company->status->value,
            'status_label' => $company->status->label(),
            'created_at' => $company->created_at?->toIso8601String(),
            'updated_at' => $company->updated_at?->toIso8601String(),
        ];

        if (! $request->user()?->can('relationships.view')) {
            return $details;
        }

        return $details + [
            'active_clients_count' => $company->active_clients_count ?? null,
            'total_clients_count' => $company->total_clients_count ?? null,
        ];
    }
}

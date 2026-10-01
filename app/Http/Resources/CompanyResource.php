<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A company, in full.
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

        return [
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
            'active_clients_count' => $company->active_clients_count ?? null,
            'total_clients_count' => $company->total_clients_count ?? null,
            'created_at' => $company->created_at?->toIso8601String(),
            'updated_at' => $company->updated_at?->toIso8601String(),
        ];
    }
}

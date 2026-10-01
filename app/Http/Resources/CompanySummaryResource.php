<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A company as it appears inside another payload: a client's list of employers,
 * a detail screen's summary.
 *
 * Separate from the full resource on purpose, so a list can never accidentally
 * carry every field of a record because the two happen to share a class.
 *
 * @mixin Company
 */
final class CompanySummaryResource extends JsonResource
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
            'status' => $company->status->value,
            'status_label' => $company->status->label(),
        ];
    }
}

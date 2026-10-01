<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\DataQuality\DataQualityFinding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One data quality finding, as the interface receives it.
 *
 * @mixin DataQualityFinding
 */
final class DataQualityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DataQualityFinding $finding */
        $finding = $this->resource;

        return $finding->toArray();
    }
}

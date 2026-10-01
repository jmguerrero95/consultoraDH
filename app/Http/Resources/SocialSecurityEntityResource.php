<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SocialSecurityEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalogue entry, in full.
 *
 * @mixin SocialSecurityEntity
 */
final class SocialSecurityEntityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SocialSecurityEntity $entity */
        $entity = $this->resource;

        return [
            'id' => $entity->id,
            'type' => $entity->type->value,
            'type_label' => $entity->type->shortLabel(),
            'type_full_label' => $entity->type->label(),
            'name' => $entity->name,
            'code' => $entity->code,
            'tax_id' => $entity->tax_id,
            'status' => $entity->status->value,
            'status_label' => $entity->status->label(),
            'affiliations_count' => $entity->affiliations_count ?? null,
            'active_affiliations_count' => $entity->active_affiliations_count ?? null,
            'created_at' => $entity->created_at?->toIso8601String(),
            'updated_at' => $entity->updated_at?->toIso8601String(),
        ];
    }
}

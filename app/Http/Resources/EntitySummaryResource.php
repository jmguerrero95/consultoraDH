<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SocialSecurityEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalogue entry as it appears inside a client's affiliations.
 *
 * @mixin SocialSecurityEntity
 */
final class EntitySummaryResource extends JsonResource
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
            'name' => $entity->name,
            'code' => $entity->code,
            'status' => $entity->status->value,
            'status_label' => $entity->status->label(),
        ];
    }
}

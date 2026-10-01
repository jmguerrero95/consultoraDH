<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ClientAffiliation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One period a client was affiliated to an entity.
 *
 * The risk level travels as both the stored integer and its label, so the
 * interface can render "Riesgo III" without a lookup table of its own and cannot
 * disagree with the domain about what level 3 means.
 *
 * @mixin ClientAffiliation
 */
final class AffiliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ClientAffiliation $affiliation */
        $affiliation = $this->resource;

        return [
            'id' => $affiliation->id,
            'client_id' => $affiliation->client_id,
            'social_security_entity_id' => $affiliation->social_security_entity_id,
            'entity' => $affiliation->relationLoaded('entity')
                ? new EntitySummaryResource($affiliation->entity)
                : null,
            'client_company_assignment_id' => $affiliation->client_company_assignment_id,
            'type' => $affiliation->type->value,
            'type_label' => $affiliation->type->shortLabel(),
            'type_full_label' => $affiliation->type->label(),
            'started_on' => $affiliation->started_on?->toDateString(),
            'ended_on' => $affiliation->ended_on?->toDateString(),
            'is_active' => $affiliation->isActive(),
            'arl_risk_class' => $affiliation->arl_risk_class,
            // Through the enum, so a value outside the range shows as the number
            // itself instead of taking the whole record down.
            'arl_risk_label' => $affiliation->arlRiskClass()?->label()
                ?? ($affiliation->hasInvalidRiskClass() ? sprintf('Valor inválido: %d', $affiliation->arl_risk_class) : null),
            'notes' => $affiliation->notes,
        ];
    }
}

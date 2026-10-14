<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ClientAffiliationObservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An affiliation the source asserted and did not name — §9.1's bare `SI`.
 *
 * This is a resource about the **file**, not about the person, and the `reason` field is what
 * keeps that visible: an interface that renders this next to a real affiliation must not let a
 * reader assume the two are the same kind of record. There is no `entity` here, and that absence
 * is the point — §9.1 forbids inventing one.
 *
 * ## Why it is a separate endpoint and not part of `/affiliations`
 *
 * Because answering "which EPS did this person have in March" with "we were not told" is the
 * false negative this record exists to prevent. Folding observations into that list would either
 * put nulls in a list of real affiliations, or filter them out — and both lose the fact. So
 * nothing that already read `client_affiliations` was changed; a caller asks for the
 * observations explicitly and decides what to do with the two lists together.
 *
 * @mixin ClientAffiliationObservation
 */
final class AffiliationObservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ClientAffiliationObservation $observation */
        $observation = $this->resource;

        return [
            'id' => $observation->id,
            'client_id' => $observation->client_id,
            'type' => $observation->type->value,
            'type_label' => $observation->type->shortLabel(),
            'type_full_label' => $observation->type->label(),

            // Explicitly null rather than absent, so a client of this API cannot tell "we did not
            // record the entity" from "this version of the API does not have the field". The first
            // is the truth; the second would be a bug in a reader.
            'social_security_entity_id' => null,
            'entity' => null,

            'reason' => $observation->reason,
            'reason_label' => self::reasonLabel($observation->reason),

            'started_on' => $observation->started_on?->toDateString(),
            'started_on_precision' => $observation->started_on_precision,
            'ended_on' => $observation->ended_on?->toDateString(),
            'ended_on_precision' => $observation->ended_on_precision,
            'is_active' => $observation->ended_on === null,

            // The months the source actually asserted, which are the evidence. §8.3: these are
            // month-precision observations and the interface must not present them as days.
            'months' => $observation->monthKeys(),

            // Whether the gap has since been filled by a real affiliation. An answered
            // observation is still shown — it records what the file said — but the interface can
            // mark it resolved rather than presenting it as an outstanding question.
            'is_answered' => $observation->isAnswered(),

            'legacy_import_id' => $observation->legacy_import_id,
            'created_at' => $observation->created_at?->toIso8601String(),
        ];
    }

    /** A sentence, not a code: this is read by a person deciding what to do next. */
    private static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'entity_unknown' => 'El archivo afirma que sí había afiliación, pero no indica a qué entidad.',
            default => 'Motivo sin descripción: '.$reason,
        };
    }
}

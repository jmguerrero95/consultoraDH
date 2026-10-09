<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Models\ClientAffiliation;

/**
 * R4 — clients and affiliations by social security entity (EPS / AFP / ARL / CCF).
 *
 * Reads A02's affiliation history and never modifies it (§9.2, §83). "Current" is derived
 * from the half-open interval A02 already stores rather than from a second definition of
 * "active": `ended_on IS NULL` for the live row, because A02 closes a row on the day the
 * next one starts.
 */
final class EntityReport implements ReportResult
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(private readonly array $filters) {}

    public function type(): ReportType
    {
        return ReportType::PorEntidad;
    }

    public function filters(): array
    {
        return $this->filters;
    }

    public function meta(): array
    {
        return [
            'report_type' => $this->type()->value,
            'title' => $this->type()->label(),
            'as_of' => now()->toDateString(),
            'filters' => ReportFilter::describe($this->filters),
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'disclaimer' => 'Documento interno de Consultora DH.',
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'entity_type', 'label' => 'Tipo', 'type' => 'text'],
            ['key' => 'entity_name', 'label' => 'Entidad', 'type' => 'text'],
            ['key' => 'client_document', 'label' => 'Cliente', 'type' => 'text'],
            ['key' => 'client_name', 'label' => 'Nombre', 'type' => 'text'],
            ['key' => 'state', 'label' => 'Estado', 'type' => 'text'],
            ['key' => 'started_on', 'label' => 'Desde', 'type' => 'date'],
            ['key' => 'ended_on', 'label' => 'Hasta', 'type' => 'date'],
            ['key' => 'arl_risk_class', 'label' => 'Clase de riesgo', 'type' => 'text'],
        ];
    }

    public function rows(): array
    {
        return ClientAffiliation::query()
            ->with('entity', 'client')
            ->join('social_security_entities', 'social_security_entities.id', '=', 'client_affiliations.social_security_entity_id')
            ->when(isset($this->filters['entity_type']), fn ($query) => $query->where('client_affiliations.type', $this->filters['entity_type']))
            ->when(isset($this->filters['entity_id']), fn ($query) => $query->where('client_affiliations.social_security_entity_id', (int) $this->filters['entity_id']))
            ->when(isset($this->filters['client_id']), fn ($query) => $query->where('client_affiliations.client_id', (int) $this->filters['client_id']))
            ->orderBy('social_security_entities.name')
            ->orderBy('client_affiliations.client_id')
            ->get(['client_affiliations.*', 'social_security_entities.name as entity_name', 'social_security_entities.type as entity_type'])
            ->map(fn (ClientAffiliation $affiliation): array => [
                'entity_type' => $affiliation->type->value,
                'entity_name' => $affiliation->getAttribute('entity_name'),
                'client_document' => $affiliation->client?->documentLabel(),
                'client_name' => $affiliation->client?->fullName(),
                'state' => $affiliation->ended_on === null ? 'Vigente' : 'Histórico',
                'started_on' => $affiliation->started_on?->toDateString(),
                'ended_on' => $affiliation->ended_on?->toDateString(),
                'arl_risk_class' => $affiliation->arl_risk_class === null ? null : (string) $affiliation->arl_risk_class,
            ])
            ->all();
    }

    public function totals(): array
    {
        return [];
    }

    public function toArray(): array
    {
        return [
            'meta' => $this->meta(),
            'columns' => $this->columns(),
            'rows' => $this->rows(),
            'totals' => $this->totals(),
        ];
    }
}

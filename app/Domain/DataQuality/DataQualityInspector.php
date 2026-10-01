<?php

declare(strict_types=1);

namespace App\Domain\DataQuality;

use App\Domain\Affiliations\ArlRiskClass;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports the situations this system knows to be suspicious.
 *
 * Deliberately plain: a small, explicit set of checks that run in SQL against the
 * database, with no scoring, no machine learning and no attempt to infer what an
 * administrator meant. The checks answer questions an operator would otherwise
 * have to answer by hand across several screens.
 *
 * The checks fall into three groups:
 *
 *  - ones the database already prevents, kept here so that a record which exists
 *    with a problem (because it was imported, or because a constraint was added
 *    later) is still reported;
 *  - ones that are genuine errors and block a write;
 *  - ones that are unusual but truthful, and only warn.
 *
 * Everything here is deterministic and readable. There is no opaque layer for a
 * later task to justify.
 */
final class DataQualityInspector
{
    /**
     * Every finding for one client.
     *
     * @return list<DataQualityFinding>
     */
    public function forClient(Client $client): array
    {
        $findings = [];

        $findings = array_merge($findings, $this->documentFindings($client));
        $findings = array_merge($findings, $this->relationshipFindings($client));
        $findings = array_merge($findings, $this->affiliationFindings($client));

        return $findings;
    }

    /**
     * Every finding for one company.
     *
     * @return list<DataQualityFinding>
     */
    public function forCompany(Company $company): array
    {
        $findings = [];

        if ($company->tax_id === null || trim($company->tax_id) === '') {
            $findings[] = DataQualityFinding::make(
                code: DataQualityCode::CompanyWithoutTaxId,
                severity: DataQualitySeverity::Notice,
                message: 'La empresa no tiene NIT registrado.',
                suggestion: 'Complete el NIT para poder detectarla en la importación.',
            );
        }

        $active = $company->activeAssignments()->count();

        if (! $company->isActive() && $active > 0) {
            $findings[] = DataQualityFinding::make(
                code: DataQualityCode::InactiveCompanyWithActiveClients,
                severity: DataQualitySeverity::Warning,
                message: sprintf(
                    'La empresa está inactiva y tiene %d cliente%s con relación abierta.',
                    $active,
                    $active === 1 ? '' : 's',
                ),
                suggestion: 'Cierre las relaciones abiertas o reactive la empresa.',
            );
        }

        return $findings;
    }

    /**
     * Findings across the whole portfolio, for the dashboard.
     *
     * @return array<string, int> code => number of records affected
     */
    public function summary(): array
    {
        return [
            DataQualityCode::DuplicateClientDocument->value => $this->duplicateDocumentCount(),
            DataQualityCode::DuplicateCompanyTaxId->value => $this->duplicateTaxIdCount(),
            DataQualityCode::MultipleActiveCompanies->value => $this->multipleActiveRelationshipsCount(),
            DataQualityCode::InactiveClientWithActiveCompanies->value => $this->inactiveClientWithRelationshipsCount(),
            DataQualityCode::InactiveCompanyWithActiveClients->value => $this->inactiveCompanyWithRelationshipsCount(),
            DataQualityCode::AffiliationTypeMismatch->value => $this->affiliationTypeMismatchCount(),
            DataQualityCode::ArlWithoutRiskClass->value => $this->arlWithoutRiskCount(),
            DataQualityCode::InvalidRiskClass->value => $this->invalidRiskCount(),
            DataQualityCode::OverlappingAffiliations->value => $this->overlappingAffiliationCount(),
        ];
    }

    /**
     * Total number of records carrying at least one blocking problem.
     */
    public function errorCount(): int
    {
        $blocking = array_map(
            static fn (DataQualityCode $code): bool => $code->blocking(),
            DataQualityCode::cases(),
        );

        return array_sum(array_filter(
            $this->summary(),
            static fn (int $count, string $code): bool => in_array($code, $blocking, true),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * The number of clients that have at least one warning.
     */
    public function warningCount(): int
    {
        $clients = Client::query()
            ->with(['companyAssignments' => fn ($q) => $q->whereNull('ended_on')])
            ->get();

        $warnings = 0;

        foreach ($clients as $client) {
            foreach ($this->forClient($client) as $finding) {
                if ($finding->severity !== DataQualitySeverity::Notice) {
                    $warnings++;

                    break;
                }
            }
        }

        return $warnings;
    }

    // --- Individual checks ---------------------------------------------------

    /**
     * @return list<DataQualityFinding>
     */
    private function documentFindings(Client $client): array
    {
        $duplicates = Client::query()
            ->where('document_type', $client->document_type->value)
            ->where('document_number', $client->document_number)
            ->whereKeyNot($client->id)
            ->count();

        if ($duplicates === 0) {
            return [];
        }

        return [DataQualityFinding::make(
            code: DataQualityCode::DuplicateClientDocument,
            severity: DataQualitySeverity::Error,
            message: sprintf(
                'Hay %d registro%s más con el mismo tipo y número de documento.',
                $duplicates,
                $duplicates === 1 ? '' : 's',
            ),
            suggestion: 'Un documento identifica a una sola persona: revise los registros duplicados.',
        )];
    }

    /**
     * @return list<DataQualityFinding>
     */
    private function relationshipFindings(Client $client): array
    {
        /** @var Collection<int, ClientCompanyAssignment> $open */
        $open = $client->companyAssignments()
            ->with('company')
            ->whereNull('ended_on')
            ->get();

        $findings = [];

        // An inactive client holding an open relationship is a contradiction
        // that cannot be produced through the API, since the domain closes the
        // relationships when it deactivates. Reported per record so the client
        // screen explains itself, not only in the portfolio counters.
        if (! $client->isActive() && $open->isNotEmpty()) {
            $names = $open
                ->map(fn (ClientCompanyAssignment $a): string => $a->company?->displayName() ?? 'Empresa desconocida')
                ->implode(', ');

            $findings[] = DataQualityFinding::make(
                code: DataQualityCode::InactiveClientWithActiveCompanies,
                severity: DataQualitySeverity::Warning,
                message: sprintf(
                    'El cliente está inactivo y tiene %d relación%s abierta%s: %s.',
                    $open->count(),
                    $open->count() === 1 ? '' : 'es',
                    $open->count() === 1 ? '' : 's',
                    $names,
                ),
                suggestion: 'Cierre las relaciones abiertas o reactive el cliente.',
            );
        }

        if ($open->count() < 2) {
            return $findings;
        }

        $unauthorised = $open->where(fn (ClientCompanyAssignment $a): bool => ! $a->isAuthorisedParallel());

        $names = $open
            ->map(fn (ClientCompanyAssignment $a): string => $a->company?->displayName() ?? 'Empresa desconocida')
            ->implode(', ');

        $findings[] = DataQualityFinding::make(
            code: DataQualityCode::MultipleActiveCompanies,
            severity: $unauthorised->isEmpty()
                ? DataQualitySeverity::Notice
                : DataQualitySeverity::Warning,
            message: sprintf(
                'El cliente tiene %d relaciones abiertas: %s.',
                $open->count(),
                $names,
            ),
            suggestion: $unauthorised->isEmpty()
                ? 'El paralelismo fue autorizado y queda documentado.'
                : 'Confirme si el paralelismo es real y autorícelo con una justificación, o transfiera.',
        );

        return $findings;
    }

    /**
     * @return list<DataQualityFinding>
     */
    private function affiliationFindings(Client $client): array
    {
        $findings = [];

        /** @var Collection<int, ClientAffiliation> $affiliations */
        $affiliations = $client->affiliations()->with('entity')->get();

        foreach ($affiliations as $affiliation) {
            $entity = $affiliation->entity;

            if ($entity !== null && $entity->type !== $affiliation->type) {
                $findings[] = DataQualityFinding::make(
                    code: DataQualityCode::AffiliationTypeMismatch,
                    severity: DataQualitySeverity::Error,
                    message: sprintf(
                        'Una afiliación de %s apunta a una entidad de %s.',
                        $affiliation->type->value,
                        $entity->type->value,
                    ),
                    suggestion: 'Corrija el tipo de la afiliación o la entidad asociada.',
                );
            }

            if ($affiliation->type->carriesRiskClass() && $affiliation->arl_risk_class === null) {
                $findings[] = DataQualityFinding::make(
                    code: DataQualityCode::ArlWithoutRiskClass,
                    severity: DataQualitySeverity::Warning,
                    message: 'La afiliación a ARL no tiene nivel de riesgo.',
                    suggestion: 'Registre el nivel de riesgo cuando esté disponible.',
                );
            }

            if ($affiliation->hasInvalidRiskClass()) {
                $findings[] = DataQualityFinding::make(
                    code: DataQualityCode::InvalidRiskClass,
                    severity: DataQualitySeverity::Error,
                    message: sprintf(
                        'Una afiliación a ARL tiene nivel de riesgo %d.',
                        $affiliation->arl_risk_class,
                    ),
                    suggestion: 'Corrija el nivel de riesgo: debe estar entre 1 y 5.',
                );
            }
        }

        // Two open rows of the same type cannot happen through the API, but the
        // check is kept so an imported or legacy record is still reported.
        $grouped = $affiliations->whereNull('ended_on')->groupBy('type');

        foreach ($grouped as $type => $rows) {
            if ($rows->count() > 1) {
                $findings[] = DataQualityFinding::make(
                    code: DataQualityCode::OverlappingAffiliations,
                    severity: DataQualitySeverity::Error,
                    message: sprintf(
                        'El cliente tiene %d afiliaciones abiertas de %s.',
                        $rows->count(),
                        (string) $type,
                    ),
                    suggestion: 'Debe existir una sola afiliación abierta por tipo.',
                );
            }
        }

        return $findings;
    }

    /**
     * How many groups hold more than one row.
     *
     * Used for the "this should not happen" counters. It selects only the grouped
     * columns, because PostgreSQL rejects `select *` next to a `GROUP BY`, and it
     * counts in SQL rather than loading the groups into memory.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    /**
     * How many clients hold more than one open company relationship.
     *
     * Public because the dashboard shows the same number, and two copies of a
     * count that is supposed to mean one thing would eventually disagree.
     */
    public function countClientsWithSeveralOpenRelationships(): int
    {
        return $this->countGrouped(
            ClientCompanyAssignment::query()->whereNull('ended_on'),
            'client_id',
        );
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function countGrouped($query, string ...$columns): int
    {
        return (int) $query
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();
    }

    // --- Aggregate counters --------------------------------------------------

    private function duplicateDocumentCount(): int
    {
        return $this->countGrouped(Client::query(), 'document_type', 'document_number');
    }

    private function duplicateTaxIdCount(): int
    {
        // Raw, because groupBy() quotes its argument as an identifier and an
        // expression is not one.
        return (int) Company::query()
            ->selectRaw('lower(btrim(tax_id)) as nit, count(*) as total')
            ->whereNotNull('tax_id')
            ->groupByRaw('lower(btrim(tax_id))')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();
    }

    private function multipleActiveRelationshipsCount(): int
    {
        return $this->countGrouped(
            ClientCompanyAssignment::query()
                ->whereNull('ended_on')
                ->whereNull('parallel_authorized_at'),
            'client_id',
        );
    }

    private function inactiveClientWithRelationshipsCount(): int
    {
        return Client::query()
            ->where('status', 'inactive')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('client_company_assignments')
                    ->whereColumn('client_company_assignments.client_id', 'clients.id')
                    ->whereNull('client_company_assignments.ended_on');
            })
            ->count();
    }

    private function inactiveCompanyWithRelationshipsCount(): int
    {
        return Company::query()
            ->where('status', 'inactive')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('client_company_assignments')
                    ->whereColumn('client_company_assignments.company_id', 'companies.id')
                    ->whereNull('client_company_assignments.ended_on');
            })
            ->count();
    }

    private function affiliationTypeMismatchCount(): int
    {
        return ClientAffiliation::query()
            ->join('social_security_entities', 'social_security_entities.id', '=', 'client_affiliations.social_security_entity_id')
            ->whereColumn('client_affiliations.type', '!=', 'social_security_entities.type')
            ->count();
    }

    private function arlWithoutRiskCount(): int
    {
        return ClientAffiliation::query()
            ->where('type', 'ARL')
            ->whereNull('arl_risk_class')
            ->count();
    }

    /**
     * Rows holding a risk class the interface could never have written.
     *
     * Counted in SQL rather than through the model, for the same reason the
     * per record check reads the column directly: the cast would raise first.
     */
    private function invalidRiskCount(): int
    {
        return (int) DB::table('client_affiliations')
            ->whereNotNull('arl_risk_class')
            ->where(fn ($query) => $query
                ->where('arl_risk_class', '<', 1)
                ->orWhere('arl_risk_class', '>', 5))
            ->count();
    }

    private function overlappingAffiliationCount(): int
    {
        return $this->countGrouped(ClientAffiliation::query()->whereNull('ended_on'), 'client_id', 'type');
    }

    /**
     * Exposed so the dashboard can show the level labels without duplicating the
     * mapping.
     *
     * @return list<array{value: int, label: string}>
     */
    public function riskOptions(): array
    {
        return ArlRiskClass::options();
    }
}

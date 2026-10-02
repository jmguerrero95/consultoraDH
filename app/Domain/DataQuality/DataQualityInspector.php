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
     * @var array<string, int>|null
     */
    private ?array $summary = null;

    /**
     * Every finding for one client.
     *
     * @return list<DataQualityFinding>
     */
    public function forClient(Client $client): array
    {
        return array_merge(
            $this->documentFindings($client),
            $this->relationshipFindings($client),
            $this->affiliationFindings($client),
        );
    }

    /**
     * The findings for one client, bucketed by the section they came from.
     *
     * A caller that may read the client's identity is not automatically entitled to
     * read which entities somebody is affiliated with, so the sections are returned
     * apart and the caller asks for the ones it may see. Which permission governs
     * which section is answered by `DataQualitySection::permission()` rather than
     * being repeated here.
     *
     * @return array<string, list<DataQualityFinding>> section => findings
     */
    public function forClientBySection(Client $client): array
    {
        $buckets = [];

        foreach ($this->forClient($client) as $finding) {
            $buckets[$finding->code->section()->value][] = $finding;
        }

        return $buckets;
    }

    /**
     * The findings whose section the caller is allowed to read.
     *
     * The permission check lives here so a controller cannot forget it: it is
     * handed something that answers "may", and gets back only what it may show.
     *
     * @param  object|null  $viewer  anything answering `can()`, as the request user does
     * @return list<DataQualityFinding>
     */
    public function forClientVisibleTo(?object $viewer, Client $client): array
    {
        $visible = [];

        foreach ($this->forClientBySection($client) as $section => $findings) {
            if (! $viewer?->can(DataQualitySection::from($section)->permission())) {
                continue;
            }

            foreach ($findings as $finding) {
                $visible[] = $finding;
            }
        }

        return $visible;
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

        // A NIT without its digit is not a wrong NIT, it is an incomplete one. The
        // supplied digit is never recalculated, so an uncertain historical value
        // stays visible as uncertain instead of being quietly replaced by a
        // confident looking one.
        if ($company->tax_id !== null && trim($company->tax_id) !== ''
            && ($company->verification_digit === null || trim($company->verification_digit) === '')) {
            $findings[] = DataQualityFinding::make(
                code: DataQualityCode::CompanyWithoutVerificationDigit,
                severity: DataQualitySeverity::Notice,
                message: 'El NIT no tiene dígito de verificación registrado.',
                suggestion: 'Confirme el dígito de verificación con la fuente; no se calcula automáticamente.',
            );
        }

        // The same conflict the aggregate counts, on the record that carries it:
        // the dashboard total and the company screen have to agree.
        $sameTaxId = Company::query()
            ->whereNotNull('tax_id')
            ->whereRaw('lower(btrim(tax_id)) = ?', [mb_strtolower(trim($company->tax_id ?? ''))])
            ->whereKeyNot($company->id)
            ->count();

        if (trim((string) $company->tax_id) !== '' && $sameTaxId > 0) {
            $findings[] = DataQualityFinding::make(
                code: DataQualityCode::DuplicateCompanyTaxId,
                severity: DataQualitySeverity::Error,
                message: sprintf(
                    'El NIT %s está registrado en %d empresa%s más.',
                    $company->tax_id,
                    $sameTaxId,
                    $sameTaxId === 1 ? '' : 's',
                ),
                suggestion: 'Un NIT identifica a una sola empresa: revise los registros duplicados.',
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
     * One aggregate query per code, and nothing that scales with the number of
     * records. The earlier version loaded every client and ran the per record
     * checks on each one, which turned the dashboard into a portfolio sized scan
     * every time somebody opened it.
     *
     * Computed once per instance. The dashboard needs the same figures three
     * times over (the whole summary, the error total and the warning total), and
     * within one request the portfolio cannot change under it, so recomputing
     * would only repeat identical reads. This is not a cache: the container
     * hands out a fresh inspector per request.
     *
     * @return array<string, int> code => number of records affected
     */
    public function summary(): array
    {
        if ($this->summary !== null) {
            return $this->summary;
        }

        return $this->summary = [
            DataQualityCode::DuplicateClientDocument->value => $this->duplicateDocumentCount(),
            DataQualityCode::DuplicateCompanyTaxId->value => $this->duplicateTaxIdCount(),
            DataQualityCode::MultipleActiveCompanies->value => $this->unauthorisedParallelCount(),
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
     *
     * Compares the string keys of `summary()` against the string values of the
     * blocking codes. Both sides are strings on purpose: the earlier version
     * mapped `blocking()` over the enum cases, which kept a list of booleans and
     * discarded the codes, so a strict `in_array()` against string keys never
     * matched and this method answered zero for a portfolio full of problems.
     */
    public function errorCount(): int
    {
        return $this->sumFor(DataQualityCode::blockingValues());
    }

    /**
     * How many findings need attention without being errors.
     *
     * Not the same set as the errors, and deliberately not a superset: a record
     * whose only problem is a warning is counted here and not in `errorCount()`,
     * so the two dashboard figures answer two different questions instead of
     * adding up to a larger number of the same one. Notices are excluded
     * entirely; they are facts about a record, not work waiting to be done.
     *
     * Aggregated in SQL, so the cost does not grow with the portfolio.
     */
    public function warningCount(): int
    {
        return $this->sumFor(DataQualityCode::warningValues());
    }

    /**
     * Overlapping relationships that somebody authorised with a reason.
     *
     * Informational, and deliberately absent from both totals: the operator made a
     * decision and wrote down why.
     */
    public function authorisedParallelCount(): int
    {
        return $this->countClientsWithSeveralOpenRelationships()
            - $this->unauthorisedParallelCount();
    }

    /**
     * @param  list<string>  $codes
     */
    private function sumFor(array $codes): int
    {
        $counts = $this->summary();

        $total = 0;

        foreach ($codes as $code) {
            $total += $counts[$code] ?? 0;
        }

        return $total;
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

        $unauthorised = $open->where(fn (ClientCompanyAssignment $a): bool => ! $a->isAuthorisedParallel())->count();

        $names = $open
            ->map(fn (ClientCompanyAssignment $a): string => $a->company?->displayName() ?? 'Empresa desconocida')
            ->implode(', ');

        // The invariant, in one sentence: for N simultaneously open
        // relationships, one may be the ordinary one and every additional row must
        // carry an explicit authorisation and a reason. So a pair of unmarked rows
        // is the problem, not the mere presence of a second row.
        //
        // This used to treat any unmarked row as unauthorised, which reported an
        // overlap created correctly through the API as a warning: the first
        // relationship is never marked, so "base plus one authorised parallel" was
        // indistinguishable from "two rows nobody authorised".
        $findings[] = DataQualityFinding::make(
            code: DataQualityCode::MultipleActiveCompanies,
            severity: $unauthorised > 1
                ? DataQualitySeverity::Warning
                : DataQualitySeverity::Notice,
            message: $unauthorised > 1
                ? sprintf(
                    'El cliente tiene %d relaciones abiertas y %d no están autorizadas: %s.',
                    $open->count(),
                    $unauthorised,
                    $names,
                )
                : sprintf(
                    'El cliente tiene %d relaciones abiertas, con el paralelismo autorizado: %s.',
                    $open->count(),
                    $names,
                ),
            suggestion: $unauthorised > 1
                ? 'Confirme cuál es la relación principal y autorice las demás con una justificación, o transfiéralas.'
                : 'El paralelismo fue autorizado y queda documentado.',
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

    /**
     * Clients holding more than one open relationship that nobody authorised.
     *
     * Public because it is the one counter an external reader asks about: the
     * number of overlaps nobody authorised, as opposed to the ones somebody did.
     *
     * Counted per client with a conditional aggregate rather than by filtering the
     * unmarked rows out before grouping. The old version did
     * `whereNull('parallel_authorized_at')` and then grouped, so a client with
     * one ordinary and one authorised parallel row contributed nothing and the
     * dashboard disagreed with the client's own screen, which read the pair as
     * an unauthorised overlap.
     *
     * A client is counted when it has at least two open rows and at most one
     * unmarked row, which is the same rule `relationshipFindings()` applies to a
     * single record.
     */
    public function unauthorisedParallelCount(): int
    {
        return (int) ClientCompanyAssignment::query()
            ->whereNull('ended_on')
            ->select('client_id')
            ->selectRaw('count(*) as open_count')
            ->selectRaw('count(*) filter (where parallel_authorized_at is null) as unmarked_count')
            ->groupBy('client_id')
            ->havingRaw('count(*) > 1')
            ->havingRaw('count(*) filter (where parallel_authorized_at is null) > 1')
            ->get()
            ->count();
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

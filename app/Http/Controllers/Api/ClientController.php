<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Affiliations\AffiliationAlreadyExists;
use App\Domain\Affiliations\DuplicateOpenRelationship;
use App\Domain\Affiliations\ManageAffiliations;
use App\Domain\Affiliations\ManageClientCompanies;
use App\Domain\Affiliations\ParallelRelationshipNotAllowed;
use App\Domain\Affiliations\TransferSourceRequired;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clients\Actions\ClientHasOpenRelationships;
use App\Domain\Clients\Actions\CreateClient;
use App\Domain\Clients\Actions\SetClientStatus;
use App\Domain\Clients\Actions\UpdateClient;
use App\Domain\DataQuality\DataQualityInspector;
use App\Domain\Shared\RecordStatus;
use App\Domain\Users\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Affiliations\CloseAffiliationRequest;
use App\Http\Requests\Affiliations\CloseRelationshipRequest;
use App\Http\Requests\Affiliations\LinkClientCompanyRequest;
use App\Http\Requests\Affiliations\ResolveAffiliationObservationRequest;
use App\Http\Requests\Affiliations\StoreAffiliationRequest;
use App\Http\Requests\Affiliations\TransferRelationshipRequest;
use App\Http\Requests\Clients\ChangeClientStatusRequest;
use App\Http\Requests\Clients\CreatePortalAccountRequest;
use App\Http\Requests\Clients\ListClientsRequest;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Resources\AffiliationObservationResource;
use App\Http\Resources\AffiliationResource;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\ClientListResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\CompanySummaryResource;
use App\Http\Resources\DataQualityResource;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientAffiliationObservation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use App\Support\Database\SchemaConstraint;
use App\Support\Database\UniqueViolation;
use App\Support\Validation\SafeSearch;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clients, and the relationships and affiliations that hang off them.
 *
 * Thin by design: every operation with rules behind it lives in a domain action,
 * and this class translates between HTTP and those actions. What remains here is
 * the part that genuinely belongs to the transport layer: pagination, filtering,
 * eager loading, and turning domain exceptions into the right status code with a
 * message a person can act on.
 */
final class ClientController extends Controller
{
    public function __construct(
        private readonly CreateClient $createClient,
        private readonly UpdateClient $updateClient,
        private readonly SetClientStatus $setClientStatus,
        private readonly ManageClientCompanies $relationships,
        private readonly ManageAffiliations $affiliations,
        private readonly DataQualityInspector $quality,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * The client list, paginated and filtered on the server.
     */
    public function index(ListClientsRequest $request): JsonResponse
    {
        $perPage = $request->perPage();
        $pattern = $request->searchPattern();
        $status = $request->statusFilter();
        $companyId = $request->companyFilter();

        $query = Client::query();

        // The list shows the open relationships on each row, so both sides are loaded
        // in two queries rather than one per client.
        //
        // Only when the caller may read them. `ClientListResource` omits the keys
        // entirely without `relationships.view`, so for that role these two queries
        // would have produced data the response is guaranteed to discard: the cost of
        // a page of joins paid for an answer that is not in the payload.
        if ($request->user()?->can('relationships.view')) {
            $query->with(['companyAssignments' => fn ($q) => $q->whereNull('ended_on')->with('company')]);
        }

        if ($pattern !== null) {
            // People type a document the way it is written, with separators, and
            // it is stored without them. Both spellings are searched, so
            // "12.345.678" and "12345678" find the same client.
            $needle = trim((string) $request->validated('search'));
            $compact = mb_strtolower(str_replace(['.', ' ', '-'], '', $needle));
            $compactPattern = $compact === mb_strtolower($needle)
                ? null
                : SafeSearch::likeNeedle($compact);

            $query->where(function ($q) use ($pattern, $compactPattern): void {
                // Every column an administrator would look somebody up by.
                foreach (['document_number', 'first_names', 'last_names', 'email', 'phone'] as $column) {
                    $q->orWhereRaw(SafeSearch::match($column), [$pattern]);
                }

                // And the whole name at once, which no single column contains. Without
                // this, "Ana María Gómez" finds nobody — and this search is how the
                // billing screen picks the client a rule is about.
                $q->orWhereRaw(SafeSearch::fullNameMatch('first_names', 'last_names'), [$pattern]);

                if ($compactPattern !== null) {
                    $q->orWhereRaw(SafeSearch::match('document_number'), [$compactPattern]);
                }
            });
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($companyId !== null) {
            $query->whereHas('companyAssignments', function ($q) use ($companyId): void {
                $q->where('company_id', $companyId)->whereNull('ended_on');
            });
        }

        $total = (clone $query)->toBase()->getCountForPagination();

        $clients = $query
            ->orderBy('last_names')
            ->orderBy('first_names')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'clients' => ClientListResource::collection($clients->items())->resolve(),
            'pagination' => [
                'total' => $total,
                'per_page' => $clients->perPage(),
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
                'from' => $clients->firstItem(),
                'to' => $clients->lastItem(),
            ],
            'filters' => [
                'search' => $request->validated('search'),
                'status' => $status,
                'company_id' => $companyId,
            ],
        ]);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        try {
            $client = $this->createClient->execute($request->validated(), $request->user());
        } catch (QueryException $e) {
            // Only this constraint means "that document already exists". Anything
            // else keeps travelling: reported as a server error, with the technical
            // detail in the log, rather than sent back as a duplicate to fix.
            if (UniqueViolation::isFor($e, SchemaConstraint::CLIENT_DOCUMENT)) {
                return $this->duplicateDocumentResponse();
            }

            throw $e;
        }

        return response()->json([
            'message' => 'El cliente fue creado correctamente.',
            'client' => new ClientResource($client),
        ], 201);
    }

    /**
     * The full profile: identity, relationships, affiliations and the history.
     *
     * Each section answers for itself. Reading a client says nothing about reading
     * somebody's employment history, and nothing about which entities they are
     * affiliated with, so `relationships.view` and `affiliations.view` gate their
     * own sections. A section the caller may not read comes back as
     * `{"visible": false}` rather than as an empty list: "you cannot see this" and
     * "there is nothing here" are different answers and only one of them is true.
     */
    public function show(Request $request, Client $client): JsonResponse
    {
        $maySeeRelationships = (bool) ($request->user()?->can('relationships.view'));
        $maySeeAffiliations = (bool) ($request->user()?->can('affiliations.view'));

        // Each side is loaded only when its section will be sent, and read only
        // inside the same guard.
        //
        // The timeline does not need either load: it reads the identifiers through a
        // subquery of its own, so it stays permission-scoped without pulling rows the
        // response may not include. Leaving the two unrelated, then reading the
        // relations anyway, would be a lazy load and would issue exactly the queries
        // the guards exist to avoid.
        if ($maySeeRelationships) {
            $client->load([
                'companyAssignments' => fn ($q) => $q->with('company')->orderByDesc('started_on'),
            ]);
        }

        if ($maySeeAffiliations) {
            $client->load([
                'affiliations' => fn ($q) => $q->with('entity')->orderByDesc('started_on'),
            ]);
        }

        $companies = ['visible' => false];
        $affiliationsSection = ['visible' => false];

        if ($maySeeRelationships) {
            $active = $client->companyAssignments->whereNull('ended_on');
            $history = $client->companyAssignments->whereNotNull('ended_on');

            $companies = [
                'visible' => true,
                'active' => AssignmentResource::collection(
                    $active->sortByDesc(fn ($a) => $a->started_on?->format('Y-m-d'))->values()
                )->resolve(),
                'history' => AssignmentResource::collection($history->values())->resolve(),
            ];
        }

        if ($maySeeAffiliations) {
            $affiliations = $client->affiliations;

            $affiliationsSection = [
                'visible' => true,
                'active' => AffiliationResource::collection(
                    $affiliations->whereNull('ended_on')->values()
                )->resolve(),
                'history' => AffiliationResource::collection(
                    $affiliations->whereNotNull('ended_on')->values()
                )->resolve(),
            ];
        }

        return response()->json([
            'client' => new ClientResource($client),
            'companies' => $companies,
            'affiliations' => $affiliationsSection,
            'history' => $this->timeline($client, $maySeeRelationships, $maySeeAffiliations),
            // The risk levels come from the domain enum rather than from a list the
            // interface keeps beside it. The two drifted once already: the frontend
            // said class V meant "no clasificado" while the domain said something
            // else, and the interface was the one people read.
            'risk_options' => $this->quality->riskOptions(),
            // Only the findings whose section the caller may read: a quality
            // problem with an affiliation says which entity is involved.
            'data_quality' => DataQualityResource::collection(
                $this->quality->forClientVisibleTo($request->user(), $client)
            )->resolve(),
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $updated = $this->updateClient->execute($client, $request->validated(), $request->user());

        return response()->json([
            'message' => 'El cliente fue actualizado correctamente.',
            'client' => new ClientResource($updated),
        ]);
    }

    /**
     * Activation and deactivation, as an explicit operation.
     *
     * There is deliberately no generic status field on the update endpoint: this
     * decision has rules attached to it that a field assignment cannot carry.
     */
    public function changeStatus(ChangeClientStatusRequest $request, Client $client): JsonResponse
    {
        $to = RecordStatus::from((string) $request->validated('status'));

        try {
            $updated = $this->setClientStatus->execute(
                $client,
                $to,
                $request->user(),
                (string) $request->validated('when'),
                $request->effectiveDate(),
            );
        } catch (ClientHasOpenRelationships $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'client_has_open_relationships',
                'open_relationships_count' => $e->openCount,
                'options' => [
                    ['value' => 'block', 'label' => 'Cancelar', 'description' => 'No modifica nada.'],
                    [
                        'value' => 'close',
                        'label' => 'Desactivar y cerrar relaciones',
                        'description' => sprintf('Cierra las %d relaciones abiertas en la fecha indicada.', $e->openCount),
                    ],
                ],
            ], 409);
        }

        return response()->json([
            'message' => $to === RecordStatus::Active
                ? 'El cliente fue reactivado correctamente.'
                : 'El cliente fue desactivado correctamente.',
            'client' => new ClientResource($updated),
        ]);
    }

    // --- Relationships --------------------------------------------------------

    public function companies(Request $request, Client $client): JsonResponse
    {
        // Open relationships first, most recent start first, then the closed
        // ones newest first. `ended_on IS NULL` has to be the first sort key so
        // PostgreSQL can use the partial index rather than sorting the lot.
        $assignments = $client->companyAssignments()
            ->with('company')
            ->orderByRaw('ended_on IS NOT NULL')
            ->orderByDesc('started_on')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'assignments' => AssignmentResource::collection($assignments)->resolve(),
        ]);
    }

    /**
     * The companies the client could be linked to, for the picker.
     *
     * Sent with the endpoint that needs them so the interface does not have to
     * hold the whole company catalogue in memory to fill one select.
     */
    public function companyOptions(Request $request): JsonResponse
    {
        // The pattern is built by `SafeSearch`, as in every other search, because this
        // picker searches the same names through its own code path and used to fold them
        // differently. "Paralela B" typed into the picker found nothing for exactly that
        // reason: the term went into `lower(...) LIKE '%Paralela B%'` unfolded, so it could
        // only ever match something already stored in lower case.
        //
        // The raw term, not a pre-escaped one: `SafeSearch::likeNeedle()` escapes, and
        // escaping twice turns `a_b` into a pattern that matches nothing.
        $pattern = $request->query('search');
        $pattern = ! is_string($pattern) || trim($pattern) === ''
            ? null
            : SafeSearch::likeNeedle($pattern);

        $companies = Company::query()
            ->when($pattern !== null, fn ($q) => $q->where(function ($q) use ($pattern): void {
                $q->whereRaw(SafeSearch::match('legal_name'), [$pattern])
                    ->orWhereRaw(SafeSearch::match("coalesce(trade_name, '')"), [$pattern]);
            }))
            ->orderBy('legal_name')
            // Inactive companies are not offered: linking to one is refused by
            // the domain, so showing it would only produce an error.
            ->where('status', RecordStatus::Active)
            // Bounded, and therefore safe only because the field next to it
            // searches. Without the search this silently hides the companies
            // that sort past the limit.
            ->limit(50)
            ->get();

        return response()->json([
            'companies' => CompanySummaryResource::collection($companies)->resolve(),
        ]);
    }

    public function linkCompany(LinkClientCompanyRequest $request, Client $client): JsonResponse
    {
        $company = Company::query()->findOrFail($request->validated('company_id'));

        try {
            $assignment = $this->relationships->link(
                client: $client,
                company: $company,
                actor: $request->user(),
                startedOn: new \DateTimeImmutable((string) $request->validated('started_on')),
                jobTitle: $request->validated('job_title'),
                notes: $request->validated('notes'),
                resolution: $request->resolution(),
                parallelReason: $request->parallelReason(),
            );
        } catch (TransferSourceRequired $e) {
            // Several open relationships: the caller has to say which one moves.
            // The answer carries them so the interface can ask, instead of the
            // server closing whichever row happened to come first.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'transfer_source_required',
                'open_assignments' => $e->openAssignmentIds,
            ], 409);
        } catch (ParallelRelationshipNotAllowed $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'parallel_relationship_not_allowed',
                'open_assignments' => $e->openAssignmentIds,
                'options' => $e->options(),
            ], 409);
        } catch (DuplicateOpenRelationship $e) {
            // A refusal the operator can act on, not a crash. The two companies are
            // the same here, so there is nothing to choose between and no list of
            // options to offer; the message names the relationship already open.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'duplicate_open_relationship',
                'company_id' => $e->company->id,
                'open_assignment_id' => $e->openAssignmentId,
            ], 422);
        } catch (QueryException $e) {
            return $this->duplicateOpenRelationshipFromIndex($e, $company)
                ?? throw $e;
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'relationship_rejected',
            ], 422);
        }

        return response()->json([
            'message' => 'La relación fue registrada correctamente.',
            'assignment' => new AssignmentResource($assignment->load('company')),
        ], 201);
    }

    public function closeRelationship(
        CloseRelationshipRequest $request,
        ClientCompanyAssignment $assignment,
    ): JsonResponse {
        try {
            $closed = $this->relationships->close(
                $assignment,
                $request->user(),
                new \DateTimeImmutable((string) $request->validated('ended_on')),
                (string) ($request->validated('reason') ?? 'Cierre manual'),
            );
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'relationship_rejected',
            ], 422);
        }

        return response()->json([
            'message' => 'La relación fue cerrada correctamente.',
            'assignment' => new AssignmentResource($closed->load('company')),
        ]);
    }

    public function transfer(
        TransferRelationshipRequest $request,
        ClientCompanyAssignment $assignment,
    ): JsonResponse {
        $to = Company::query()->findOrFail($request->validated('to_company_id'));

        try {
            $opened = $this->relationships->transfer(
                $assignment,
                $to,
                $request->user(),
                new \DateTimeImmutable((string) $request->validated('effective_on')),
                $request->validated('job_title'),
                $request->validated('notes'),
            );
        } catch (DuplicateOpenRelationship $e) {
            // Moving somebody to a company they are already with, or to one they are
            // already employed by, is refused with the same answer the link gives.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'duplicate_open_relationship',
                'company_id' => $e->company->id,
                'open_assignment_id' => $e->openAssignmentId,
            ], 422);
        } catch (QueryException $e) {
            return $this->duplicateOpenRelationshipFromIndex($e, $to)
                ?? throw $e;
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'transfer_rejected',
            ], 422);
        }

        $assignment->refresh();

        return response()->json([
            'message' => 'El cliente fue transferido correctamente.',
            'closed_assignment' => new AssignmentResource($assignment->load('company')),
            'assignment' => new AssignmentResource($opened->load('company')),
        ]);
    }

    // --- Affiliations ---------------------------------------------------------

    public function affiliations(Request $request, Client $client): JsonResponse
    {
        $affiliations = $client->affiliations()
            ->with('entity')
            ->orderByDesc('started_on')
            ->get();

        return response()->json([
            'affiliations' => AffiliationResource::collection($affiliations)->resolve(),
        ]);
    }

    /**
     * GET /api/clients/{client}/affiliation-observations
     *
     * FIX-IMPORT-02 Correction C. §9.1's bare `SI`: the file said the person was affiliated and
     * did not say to what.
     *
     * ## Why this is not part of `affiliations()`
     *
     * Because that endpoint answers "which entities did this person have", and an observation
     * has no entity. Adding it there would mean either a list of real affiliations with nulls in
     * it, or a filter that drops them — and both destroy the record of a gap that was real. A03
     * in particular reads `client_affiliations` to decide what may be billed; an observation must
     * never reach that read, and the cheapest way to guarantee it is for the two to be different
     * queries.
     *
     * So nothing that already read `client_affiliations` was changed. That is a deliberate
     * constraint rather than an accident of scope: a record nobody can be surprised by cannot
     * turn a working screen into a 500.
     */
    public function affiliationObservations(Client $client): JsonResponse
    {
        $observations = ClientAffiliationObservation::query()
            ->where('client_id', $client->id)
            ->orderByDesc('started_on')
            ->get();

        return response()->json([
            'affiliation_observations' => AffiliationObservationResource::collection($observations)->resolve(),
        ]);
    }

    /**
     * POST /api/clients/{client}/affiliation-observations/{observation}/resolve
     *
     * Answer §9.1's bare `SI`: name the entity the file said existed and did not identify.
     *
     * ## Why this goes through A02 rather than writing the row here
     *
     * Three reasons, and the first is the important one:
     *
     *  1. an `client_affiliations` row **is** an affiliation. Writing it outside `ManageAffiliations`
     *     would create a record the domain's invariants — §9.5's one open affiliation per type,
     *     the client and entity locks, the status checks — never saw. The observation would stop
     *     being a note about a gap and become an affiliation that skipped every rule about
     *     affiliations;
     *  2. the audit trail is `ManageAffiliations`' own event, so §17.4's "who changed this
     *     person's record" is answered by the same write as every other affiliation;
     *  3. `AffiliationAlreadyExists` and `DomainException` already carry the sentences to show a
     *     person, and duplicating them here would be how two versions of the same message start
     *     to drift.
     *
     * ## The observation is not deleted, and not modified
     *
     * It records what the **file** said, and the file still says `SI`. Deleting it would erase the
     * provenance; editing it would make the master's record of the source disagree with the source.
     * What changes is `isAnswered()` — computed, not stored — because a real affiliation of the
     * same type now covers the months it observed.
     *
     * ## No duplicates
     *
     * Refused with 409 when the gap is already filled, so a double-click cannot produce two
     * identical affiliations and §9.5's invariant does not have to catch it after the fact.
     */
    public function resolveAffiliationObservation(
        ResolveAffiliationObservationRequest $request,
        Client $client,
        ClientAffiliationObservation $observation,
    ): JsonResponse {
        // Scoped to the client in the route. Without this a request for `/clients/1/…/99` would
        // resolve an observation belonging to somebody else, because `ClientAffiliationObservation`
        // is bound by id alone.
        abort_unless($observation->client_id === $client->id, 404);

        if ($observation->isAnswered()) {
            return response()->json([
                'message' => 'Esta afiliación ya tiene una entidad identificada.',
                'code' => 'already_answered',
            ], 409);
        }

        $entity = SocialSecurityEntity::query()
            ->findOrFail($request->validated('social_security_entity_id'));

        // The type the **file** asserted, compared against the entity being named. An AFP gap may
        // not be answered with an EPS, and this is the only place that knows both.
        if ($entity->type->value !== $observation->type->value) {
            return response()->json([
                'message' => sprintf(
                    'La observación corresponde a una afiliación de tipo %s y la entidad indicada es de tipo %s.',
                    $observation->type->label(),
                    $entity->type->label(),
                ),
                'code' => 'entity_type_mismatch',
            ], 422);
        }

        if ($request->validated('type') !== $observation->type->value) {
            return response()->json([
                'message' => sprintf(
                    'La observación corresponde a una afiliación de tipo %s.',
                    $observation->type->label(),
                ),
                'code' => 'entity_type_mismatch',
            ], 422);
        }

        $startedOn = $observation->started_on !== null
            ? new \DateTimeImmutable($observation->started_on->toDateString())
            : null;

        $notes = $this->resolutionNote($observation, $request->validated('notes'));

        try {
            $affiliation = $this->affiliations->create(
                client: $client,
                entity: $entity,
                actor: $request->user(),
                // §8's own vocabulary: the date the file stated, at the precision it stated it.
                // Null when the source gave no date, which A02 accepts — §8 widens the constraint
                // precisely so an unknown start is preserved rather than invented.
                startedOn: $startedOn,
                notes: $notes,
            );
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'affiliation_rejected',
            ], 422);
        }

        return response()->json([
            'message' => 'La entidad de la afiliación quedó identificada.',
            'affiliation' => new AffiliationResource($affiliation->load('entity')),
            'observation' => new AffiliationObservationResource($observation->fresh()),
        ]);
    }

    /**
     * The note written on the affiliation this resolution creates.
     *
     * Not optional and not free text alone: it says **which** observation it answers and that it
     * came from an import, so a reader of the master's history in three years' time can tell a
     * fund that was named by a person from one the file wrote out. The operator's own note is
     * appended rather than replaced.
     */
    private function resolutionNote(
        ClientAffiliationObservation $observation,
        ?string $operatorNote,
    ): string {
        $note = sprintf(
            'Entidad identificada por revisión manual sobre una observación del archivo ('
            .'meses observados: %s; motivo registrado: %s).',
            $observation->monthKeys() === [] ? 'sin meses declarados' : implode(', ', $observation->monthKeys()),
            $observation->reason,
        );

        if ($observation->legacy_import_id !== null) {
            $note .= sprintf(' Importación #%d.', $observation->legacy_import_id);
        }

        if (is_string($operatorNote) && trim($operatorNote) !== '') {
            $note .= ' Nota del revisor: '.trim($operatorNote);
        }

        return $note;
    }

    public function storeAffiliation(
        StoreAffiliationRequest $request,
        Client $client,
    ): JsonResponse {
        $entity = SocialSecurityEntity::query()
            ->findOrFail($request->validated('social_security_entity_id'));

        $assignment = null;

        if ($request->validated('client_company_assignment_id') !== null) {
            $assignment = ClientCompanyAssignment::query()
                ->findOrFail($request->validated('client_company_assignment_id'));
        }

        try {
            $affiliation = $this->affiliations->create(
                client: $client,
                entity: $entity,
                actor: $request->user(),
                startedOn: $request->validated('started_on') !== null
                    ? new \DateTimeImmutable((string) $request->validated('started_on'))
                    : null,
                riskClass: $request->riskClass(),
                underAssignment: $assignment,
                notes: $request->validated('notes'),
                closeCurrent: $request->shouldReplaceCurrent(),
                closeCurrentOn: $request->validated('effective_date') !== null
                    ? new \DateTimeImmutable((string) $request->validated('effective_date'))
                    : null,
            );
        } catch (AffiliationAlreadyExists $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'affiliation_already_exists',
                'existing_affiliation_id' => $e->existingAffiliationId,
                'existing_entity_id' => $e->existingEntityId,
                'options' => $e->options(),
            ], 409);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'affiliation_rejected',
            ], 422);
        } catch (QueryException $e) {
            if (! UniqueViolation::isFor($e, SchemaConstraint::AFFILIATION_OPEN_PER_TYPE)) {
                throw $e;
            }

            return response()->json([
                'message' => 'El cliente ya tiene una afiliación abierta de ese tipo.',
                'code' => 'affiliation_already_exists',
            ], 409);
        }

        return response()->json([
            'message' => 'La afiliación fue registrada correctamente.',
            'affiliation' => new AffiliationResource($affiliation->load('entity')),
        ], 201);
    }

    public function closeAffiliation(
        CloseAffiliationRequest $request,
        ClientAffiliation $affiliation,
    ): JsonResponse {
        try {
            $closed = $this->affiliations->close(
                $affiliation,
                $request->user(),
                new \DateTimeImmutable((string) $request->validated('ended_on')),
                (string) ($request->validated('reason') ?? 'Cierre manual'),
            );
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'affiliation_rejected',
            ], 422);
        }

        return response()->json([
            'message' => 'La afiliación fue cerrada correctamente.',
            'affiliation' => new AffiliationResource($closed->load('entity')),
        ]);
    }

    /**
     * Change to another entity of the same type.
     */
    public function changeAffiliationEntity(
        StoreAffiliationRequest $request,
        ClientAffiliation $affiliation,
    ): JsonResponse {
        $to = SocialSecurityEntity::query()
            ->findOrFail($request->validated('social_security_entity_id'));

        try {
            $opened = $this->affiliations->changeEntity(
                $affiliation,
                $to,
                $request->user(),
                new \DateTimeImmutable((string) ($request->validated('effective_date') ?? 'today')),
                $request->riskClass(),
                $request->validated('notes'),
            );
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'affiliation_rejected',
            ], 422);
        }

        $affiliation->refresh();

        return response()->json([
            'message' => 'La afiliación fue actualizada correctamente.',
            'closed_affiliation' => new AffiliationResource($affiliation->load('entity')),
            'affiliation' => new AffiliationResource($opened->load('entity')),
        ]);
    }

    // --- Helpers --------------------------------------------------------------

    /**
     * Whether this user may see the person's affiliations at all.
     */
    /**
     * The audit entries about this client and the rows that belong to it.
     *
     * Read only, and deliberately not the full audit administration screen, which
     * is a later task. It answers "what happened to this person" and nothing
     * more.
     *
     * @return list<array<string, mixed>>
     */
    private function timeline(
        Client $client,
        bool $maySeeRelationships = true,
        bool $maySeeAffiliations = true,
    ): array {
        // The actor is read for every entry, so the user is eager loaded: fifty
        // entries cost one query for the users instead of fifty.
        //
        // The comment below says the answer "was already one eager-load away" —
        // it was, and it was not taken. A client's history cost one query per
        // event.
        $entries = AuditEvent::query()
            ->with('user')
            ->where('subject_type', Client::class)
            ->where('subject_id', $client->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Relationship events are about employment history, so they answer to
        // `relationships.view` rather than to `clients.view`.
        $relationshipEntries = $maySeeRelationships
            ? AuditEvent::query()
                ->with('user')
                ->where('subject_type', ClientCompanyAssignment::class)
                ->whereIn('subject_id', $client->companyAssignments()->select('id'))
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
            : new Collection;

        // Only for a role that may see affiliations at all. The events carry the
        // type and the entity of the affiliation in their metadata, so showing
        // them to a role without `affiliations.view` would answer a question
        // that role is not allowed to ask.
        $affiliationEntries = $maySeeAffiliations
            ? AuditEvent::query()
                ->with('user')
                ->where('subject_type', ClientAffiliation::class)
                ->whereIn('subject_id', $client->affiliations()->select('id'))
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
            : new Collection;

        return $entries
            ->concat($relationshipEntries)
            ->concat($affiliationEntries)
            ->sortByDesc('created_at')
            ->take(50)
            ->map(fn (AuditEvent $event): array => [
                'action' => $event->action,
                'subject' => $event->subjectLabel(),
                'at' => $event->created_at->toIso8601String(),
                'metadata' => $event->metadata,
                // Who did it. §17.4 asks a reviewer who changed a client's record, and the
                // answer was already one eager-load away — the timeline read as a list of
                // anonymous verbs without it.
                //
                // `null` rather than omitted when there is no user, because the interface asks
                // "quién" and has to be able to say "el sistema" rather than show nothing.
                'actor' => $event->user?->name,
                'actor_kind' => $event->user_id === null ? 'system' : 'user',
            ])
            ->values()
            ->all();
    }

    /**
     * Translate a uniqueness violation into a message, never a database error.
     */
    private function duplicateDocumentResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Ya existe un cliente registrado con ese tipo y número de documento.',
            'code' => 'duplicate_document',
            'errors' => [
                'document_number' => ['Ya existe un cliente registrado con ese tipo y número de documento.'],
            ],
        ], 422);
    }

    /**
     * The database refused a second open relationship for the same client and company.
     *
     * The domain refuses this first, so reaching here means something got past it:
     * two concurrent requests, both of which read nothing open, both of which
     * inserted. The partial unique index is the authority that holds the line, and
     * this turns its violation into the same answer the domain gives, so the two
     * paths a caller can take do not produce two different experiences.
     *
     * Without this the caller receives a 500 for something the domain already
     * described perfectly well a moment earlier.
     *
     * Only this one index is translated. `UniqueViolation::isFor()` matches the
     * constraint name, so an unrelated violation is re-thrown untouched rather than
     * being dressed up as a duplicate the caller did not cause.
     */
    private function duplicateOpenRelationshipFromIndex(QueryException $e, Company $company): ?JsonResponse
    {
        if (! UniqueViolation::isFor($e, SchemaConstraint::ASSIGNMENT_OPEN_PER_COMPANY)) {
            return null;
        }

        return response()->json([
            'message' => sprintf(
                'El cliente ya tiene una relación abierta con esta empresa (%s). '
                .'No se pueden abrir dos relaciones con la misma empresa al mismo tiempo.',
                $company->displayName(),
            ),
            'code' => 'duplicate_open_relationship',
            'company_id' => $company->id,
        ], 422);
    }

    public function createPortalAccount(CreatePortalAccountRequest $request, Client $client): JsonResponse
    {
        $data = $request->validated();

        $existing = User::query()->where('client_id', $client->id)->first();
        if ($existing !== null) {
            return response()->json([
                'message' => 'El cliente ya tiene una cuenta de portal.',
                'code' => 'portal_account_exists',
            ], 409);
        }

        try {
            // The insert runs inside a transaction of its own so a unique
            // violation can be undone before the answer is produced. PostgreSQL
            // aborts the whole transaction when a statement fails, so a
            // violation inside an enclosing transaction would leave the
            // connection unusable for the answer that follows; the savepoint
            // rollback clears that.
            $user = DB::transaction(fn (): User => User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'account_type' => 'client',
                'client_id' => $client->id,
                'status' => UserStatus::Active,
            ]));
        } catch (QueryException $e) {
            $conflict = $this->portalAccountConflict($e);

            if ($conflict !== null) {
                return $conflict;
            }

            throw $e;
        }

        $this->audit->record(AuditAction::PortalAccountCreated, $request->user(), [
            'client_id' => $client->id,
            'email' => $data['email'],
        ], null, $client);

        return response()->json([
            'id' => (int) $user->id,
            'email' => $user->email,
            'account_type' => $user->account_type,
        ], 201);
    }

    /**
     * The conflict two administrators can create at the same time.
     *
     * The existence check above answers the ordinary case with a friendly
     * message. Two administrators confirming the same client at the same moment
     * both pass it, and the database is what stops the second insert. That
     * violation is a conflict, not a server error, so it is translated here
     * into the same answer the check would have given.
     *
     * Only these two indexes are translated. An unrelated database failure
     * keeps travelling to the central handler, where it is logged and rendered
     * as a safe server error: dressing it up as a duplicate would tell the
     * operator to change something that was not the problem.
     */
    private function portalAccountConflict(QueryException $e): ?JsonResponse
    {
        if (UniqueViolation::isFor($e, SchemaConstraint::PORTAL_ACCOUNT_PER_CLIENT)) {
            return response()->json([
                'message' => 'El cliente ya tiene una cuenta de portal.',
                'code' => 'portal_account_exists',
            ], 409);
        }

        if (UniqueViolation::isFor($e, SchemaConstraint::USER_EMAIL)) {
            return response()->json([
                'message' => 'Ese correo ya está en uso por otra cuenta.',
                'code' => 'email_already_in_use',
            ], 409);
        }

        return null;
    }

    public function deactivatePortalAccount(Request $request, User $user): JsonResponse
    {
        abort_unless($user->account_type === 'client', 404);

        $user->forceFill(['status' => UserStatus::Inactive])->save();

        $this->audit->record(AuditAction::PortalAccountDeactivated, $request->user(), [
            'client_id' => $user->client_id,
        ], null, $user->client);

        return response()->json(['message' => 'Cuenta desactivada.']);
    }
}

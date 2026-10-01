<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Affiliations\AffiliationAlreadyExists;
use App\Domain\Affiliations\ManageAffiliations;
use App\Domain\Affiliations\ManageClientCompanies;
use App\Domain\Affiliations\ParallelRelationshipNotAllowed;
use App\Domain\Clients\Actions\ClientHasOpenRelationships;
use App\Domain\Clients\Actions\CreateClient;
use App\Domain\Clients\Actions\SetClientStatus;
use App\Domain\Clients\Actions\UpdateClient;
use App\Domain\DataQuality\DataQualityInspector;
use App\Domain\Shared\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Affiliations\CloseAffiliationRequest;
use App\Http\Requests\Affiliations\CloseRelationshipRequest;
use App\Http\Requests\Affiliations\LinkClientCompanyRequest;
use App\Http\Requests\Affiliations\StoreAffiliationRequest;
use App\Http\Requests\Affiliations\TransferRelationshipRequest;
use App\Http\Requests\Clients\ChangeClientStatusRequest;
use App\Http\Requests\Clients\ListClientsRequest;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Resources\AffiliationResource;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\ClientListResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\CompanySummaryResource;
use App\Http\Resources\DataQualityResource;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\SocialSecurityEntity;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        $query = Client::query()
            // The list shows the open relationships on each row, so both sides are
            // loaded in two queries rather than one per client.
            ->with(['companyAssignments' => fn ($q) => $q->whereNull('ended_on')->with('company')]);

        if ($pattern !== null) {
            // People type a document the way it is written, with separators, and
            // it is stored without them. Both spellings are searched, so
            // "12.345.678" and "12345678" find the same client.
            $needle = trim((string) $request->validated('search'));
            $compact = mb_strtolower(str_replace(['.', ' ', '-'], '', $needle));
            $compactPattern = $compact === mb_strtolower($needle)
                ? null
                : '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $compact).'%';

            $query->where(function ($q) use ($pattern, $compactPattern): void {
                // Every column an administrator would look somebody up by.
                foreach (['document_number', 'first_names', 'last_names', 'email', 'phone'] as $column) {
                    $q->orWhereRaw("lower({$column}) LIKE ? ESCAPE '\\'", [$pattern]);
                }

                if ($compactPattern !== null) {
                    $q->orWhereRaw("lower(document_number) LIKE ? ESCAPE '\\'", [$compactPattern]);
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
            return $this->duplicateDocumentResponse();
        }

        return response()->json([
            'message' => 'El cliente fue creado correctamente.',
            'client' => new ClientResource($client),
        ], 201);
    }

    /**
     * The full profile: identity, relationships, affiliations and the history.
     */
    public function show(Request $request, Client $client): JsonResponse
    {
        $client->load([
            'companyAssignments' => fn ($q) => $q->with('company')->orderByDesc('started_on'),
            'affiliations' => fn ($q) => $q->with('entity')->orderByDesc('started_on'),
        ]);

        $active = $client->companyAssignments->whereNull('ended_on');
        $history = $client->companyAssignments->whereNotNull('ended_on');
        $affiliations = $client->affiliations;

        return response()->json([
            'client' => new ClientResource($client),
            'companies' => [
                'active' => AssignmentResource::collection(
                    $active->sortByDesc(fn ($a) => $a->started_on?->format('Y-m-d'))->values()
                )->resolve(),
                'history' => AssignmentResource::collection($history->values())->resolve(),
            ],
            'affiliations' => $this->maySeeAffiliations($request)
                ? [
                    'visible' => true,
                    'active' => AffiliationResource::collection(
                        $affiliations->whereNull('ended_on')->values()
                    )->resolve(),
                    'history' => AffiliationResource::collection(
                        $affiliations->whereNotNull('ended_on')->values()
                    )->resolve(),
                ]
                // Said as "not permitted" rather than as an empty list: "you
                // cannot see this" and "there is nothing here" are different
                // answers, and only one of them is true.
                : ['visible' => false],
            'history' => $this->timeline($client, $this->maySeeAffiliations($request)),
            'data_quality' => DataQualityResource::collection(
                collect($this->quality->forClient($client))
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
        // Folded for the same reason `ListQueryRequest::searchPattern()` folds it:
        // the columns are compared through `lower()`, so an unfolded term would
        // never match. "Paralela B" typed into the picker found nothing for
        // exactly that reason.
        $pattern = $this->escape($request->query('search'));
        $pattern = $pattern === null ? null : '%'.mb_strtolower($pattern).'%';

        $companies = Company::query()
            ->when($pattern !== null, fn ($q) => $q->where(function ($q) use ($pattern): void {
                $q->whereRaw("lower(legal_name) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("lower(coalesce(trade_name, '')) LIKE ? ESCAPE '\\'", [$pattern]);
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
                effectiveDate: $request->validated('effective_date'),
                parallelReason: $request->parallelReason(),
            );
        } catch (ParallelRelationshipNotAllowed $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'parallel_relationship_not_allowed',
                'open_assignments' => $e->openAssignmentIds,
                'options' => $e->options(),
            ], 409);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'No fue posible registrar la relación.',
                'code' => 'relationship_conflict',
            ], 409);
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
    private function maySeeAffiliations(Request $request): bool
    {
        return (bool) ($request->user()?->can('affiliations.view'));
    }

    /**
     * The audit entries about this client and the rows that belong to it.
     *
     * Read only, and deliberately not the full audit administration screen, which
     * is a later task. It answers "what happened to this person" and nothing
     * more.
     *
     * @return list<array<string, mixed>>
     */
    private function timeline(Client $client, bool $maySeeAffiliations = true): array
    {
        $entries = AuditEvent::query()
            ->where('subject_type', Client::class)
            ->where('subject_id', $client->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $relationshipEntries = AuditEvent::query()
            ->where('subject_type', ClientCompanyAssignment::class)
            ->whereIn('subject_id', $client->companyAssignments()->select('id'))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Only for a role that may see affiliations at all. The events carry the
        // type and the entity of the affiliation in their metadata, so showing
        // them to a role without `affiliations.view` would answer a question
        // that role is not allowed to ask.
        $affiliationEntries = $maySeeAffiliations
            ? AuditEvent::query()
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
            ->map(fn ($event): array => [
                'action' => $event->action,
                'subject' => $event->subjectLabel(),
                'at' => $event->created_at->toIso8601String(),
                'metadata' => $event->metadata,
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

    private function escape(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($value));
    }
}

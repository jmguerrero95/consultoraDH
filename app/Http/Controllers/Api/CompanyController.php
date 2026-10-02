<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Companies\Actions\CompanyHasActiveClients;
use App\Domain\Companies\Actions\CreateCompany;
use App\Domain\Companies\Actions\SetCompanyStatus;
use App\Domain\Companies\Actions\UpdateCompany;
use App\Domain\Companies\ConflictingVerificationDigit;
use App\Domain\Companies\TaxId;
use App\Domain\DataQuality\DataQualityInspector;
use App\Domain\Shared\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\ChangeCompanyStatusRequest;
use App\Http\Requests\Companies\ListCompaniesRequest;
use App\Http\Requests\Companies\StoreCompanyRequest;
use App\Http\Requests\Companies\UpdateCompanyRequest;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\DataQualityResource;
use App\Models\Company;
use App\Support\Database\SchemaConstraint;
use App\Support\Database\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Companies.
 *
 * Thin for the same reason as the client controller: the rules live in the domain
 * actions and this class only deals with HTTP.
 */
final class CompanyController extends Controller
{
    public function __construct(
        private readonly CreateCompany $createCompany,
        private readonly UpdateCompany $updateCompany,
        private readonly SetCompanyStatus $setCompanyStatus,
        private readonly DataQualityInspector $quality,
    ) {}

    public function index(ListCompaniesRequest $request): JsonResponse
    {
        $perPage = $request->perPage();
        $pattern = $request->searchPattern();
        $status = $request->statusFilter();

        $query = Company::query();

        // Counting the workforce is reading relationships. The resource omits the
        // keys without the permission, and not counting at all keeps the list
        // honest and the query cheap for the roles that may not look.
        if ($request->user()?->can('relationships.view')) {
            $query->withCount([
                'assignments as total_clients_count',
                'activeAssignments as active_clients_count',
            ]);
        }

        if ($pattern !== null) {
            // A NIT is written with thousand separators and typed with them:
            // `900.123.456-3` must find the record stored as `900123456-3`. So
            // The two columns are searched separately, because a NIT is stored as
            // a number and a digit: `900.123.222` has to find `900111222`, and
            // `900111222-3` has to find the row that carries both.
            $patterns = TaxId::searchPatterns((string) $request->validated('search'));

            $query->where(function ($q) use ($pattern, $patterns): void {
                $q->whereRaw("lower(legal_name) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("lower(coalesce(trade_name, '')) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("lower(coalesce(tax_id, '')) LIKE ? ESCAPE '\\'", [$patterns['number']])
                    ->orWhereRaw(
                        "(lower(coalesce(tax_id, '')) || '-' || coalesce(verification_digit, '')) LIKE ? ESCAPE '\\'",
                        [$patterns['combined']],
                    );
            });
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        $total = (clone $query)->toBase()->getCountForPagination();

        $companies = $query
            ->orderBy('legal_name')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'companies' => CompanyResource::collection($companies->items())->resolve(),
            'pagination' => [
                'total' => $total,
                'per_page' => $companies->perPage(),
                'current_page' => $companies->currentPage(),
                'last_page' => $companies->lastPage(),
                'from' => $companies->firstItem(),
                'to' => $companies->lastItem(),
            ],
            'filters' => [
                'search' => $request->validated('search'),
                'status' => $status,
            ],
        ]);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        try {
            $company = $this->createCompany->execute($request->validated(), $request->user());
        } catch (ConflictingVerificationDigit $e) {
            // The same refusal the update path makes. Two answers to the same
            // question in one request is not something this system decides for the
            // operator, and it must not decide it differently depending on whether
            // the company already exists.
            return $this->conflictingDigitResponse($e);
        } catch (QueryException $e) {
            if (! UniqueViolation::isFor($e, SchemaConstraint::COMPANY_TAX_ID)) {
                throw $e;
            }

            return response()->json([
                'message' => 'Ya existe una empresa registrada con ese NIT.',
                'code' => 'duplicate_tax_id',
                'errors' => [
                    'tax_id' => ['Ya existe una empresa registrada con ese NIT.'],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'La empresa fue creada correctamente.',
            'company' => new CompanyResource($company),
        ], 201);
    }

    /**
     * A company and the clients currently linked to it.
     *
     * The client list is relationship data, so it answers to both `clients.view`
     * and `relationships.view`: knowing a company exists does not entitle anybody to
     * the list of people who work there, and the names in it are personal data. The
     * section says so rather than coming back empty.
     */
    public function show(Request $request, Company $company): JsonResponse
    {
        $maySeeClients = (bool) ($request->user()?->can('clients.view'))
            && (bool) ($request->user()?->can('relationships.view'));

        $company->load([
            'assignments' => fn ($q) => $q->with('client')->orderByDesc('started_on'),
        ]);

        // The counts are part of the record the resource describes, so they are
        // loaded here too rather than left null on a detail response that otherwise
        // carries them. And they are workforce information, so they are loaded only
        // for a viewer who may read relationships.
        if ($maySeeClients) {
            $company->loadCount([
                'assignments as total_clients_count',
                'activeAssignments as active_clients_count',
            ]);
        }

        $active = $company->assignments->whereNull('ended_on');
        $history = $company->assignments->whereNotNull('ended_on');

        return response()->json([
            'company' => new CompanyResource($company),
            'clients' => $maySeeClients ? [
                'visible' => true,
                'active' => AssignmentResource::collection(
                    $active->sortBy(fn ($a) => $a->client?->fullName() ?? '')->values()
                )->resolve(),
                'history_count' => $history->count(),
            ] : ['visible' => false],
            // Filtered the same way as the sections above, so a quality finding is
            // not a way around the permission that governs its subject.
            'data_quality' => DataQualityResource::collection(
                $this->quality->forCompanyVisibleTo($request->user(), $company)
            )->resolve(),
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        try {
            $updated = $this->updateCompany->execute($company, $request->companyAttributes(), $request->user());
        } catch (ConflictingVerificationDigit $e) {
            // Two answers to the same question in one request. Choosing one silently
            // would decide somebody else's tax identity for them.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'conflicting_verification_digit',
                'errors' => [
                    'verification_digit' => [$e->getMessage()],
                ],
            ], 422);
        } catch (QueryException $e) {
            if (! UniqueViolation::isFor($e, SchemaConstraint::COMPANY_TAX_ID)) {
                throw $e;
            }

            return response()->json([
                'message' => 'Ya existe una empresa registrada con ese NIT.',
                'code' => 'duplicate_tax_id',
                'errors' => [
                    'tax_id' => ['Ya existe una empresa registrada con ese NIT.'],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'La empresa fue actualizada correctamente.',
            'company' => new CompanyResource($updated),
        ]);
    }

    /**
     * Activation and deactivation.
     *
     * Deactivating is refused while clients still have open relationships, and
     * the conflict says how many. There is no automatic answer available here:
     * closing everybody's employment to satisfy a status flag would rewrite the
     * history of every one of them.
     */
    public function changeStatus(ChangeCompanyStatusRequest $request, Company $company): JsonResponse
    {
        $to = RecordStatus::from((string) $request->validated('status'));

        try {
            $updated = $this->setCompanyStatus->execute($company, $to, $request->user());
        } catch (CompanyHasActiveClients $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'company_has_active_clients',
                'active_clients_count' => $e->activeCount,
            ], 409);
        }

        return response()->json([
            'message' => $to === RecordStatus::Active
                ? 'La empresa fue reactivada correctamente.'
                : 'La empresa fue desactivada correctamente.',
            'company' => new CompanyResource($updated),
        ]);
    }

    /**
     * Two contradictory verification digits in one request.
     *
     * One response for both write paths, because the rule is one rule. A difference
     * in behaviour between creating and editing the same company would be a
     * difference nobody could explain.
     */
    private function conflictingDigitResponse(ConflictingVerificationDigit $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'conflicting_verification_digit',
            'errors' => [
                'verification_digit' => [$e->getMessage()],
            ],
        ], 422);
    }
}

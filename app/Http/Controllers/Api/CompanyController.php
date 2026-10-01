<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Companies\Actions\CompanyHasActiveClients;
use App\Domain\Companies\Actions\CreateCompany;
use App\Domain\Companies\Actions\SetCompanyStatus;
use App\Domain\Companies\Actions\UpdateCompany;
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

        $query = Company::query()->withCount([
            'assignments as total_clients_count',
            'activeAssignments as active_clients_count',
        ]);

        if ($pattern !== null) {
            // A NIT is written with thousand separators and typed with them:
            // `900.123.456-3` must find the record stored as `900123456-3`. So
            // the tax column is searched with both spellings. The hyphen is NOT
            // one of the separators removed, because in a NIT it is meaningful:
            // it separates the number from its verification digit.
            $needle = trim((string) $request->validated('search'));
            $unseparated = mb_strtolower(str_replace(['.', ' '], '', $needle));

            $taxPattern = '%'.$unseparated.'%';

            $query->where(function ($q) use ($pattern, $taxPattern): void {
                $q->whereRaw("lower(legal_name) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("lower(coalesce(trade_name, '')) LIKE ? ESCAPE '\\'", [$pattern])
                    // Always searched, including when the term looks like a name.
                    // A NIT typed as `900111222-3` matches nothing in the name
                    // columns, and the whole point is that it should.
                    ->orWhereRaw("lower(coalesce(tax_id, '')) LIKE ? ESCAPE '\\'", [$taxPattern]);
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
        } catch (QueryException $e) {
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

    public function show(Request $request, Company $company): JsonResponse
    {
        $company->load([
            'assignments' => fn ($q) => $q->with('client')->orderByDesc('started_on'),
        ]);

        $active = $company->assignments->whereNull('ended_on');
        $history = $company->assignments->whereNotNull('ended_on');

        return response()->json([
            'company' => new CompanyResource($company),
            'clients' => [
                'active' => AssignmentResource::collection(
                    $active->sortBy(fn ($a) => $a->client?->fullName() ?? '')->values()
                )->resolve(),
                'history_count' => $history->count(),
            ],
            'data_quality' => DataQualityResource::collection(
                collect($this->quality->forCompany($company))
            )->resolve(),
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        try {
            $updated = $this->updateCompany->execute($company, $request->validated(), $request->user());
        } catch (QueryException $e) {
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
}

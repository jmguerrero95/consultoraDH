<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Affiliations\Actions\EntityHasOpenAffiliations;
use App\Domain\Affiliations\Actions\ManageCatalogueEntities;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\ListEntitiesRequest;
use App\Http\Requests\Catalogue\StoreEntityRequest;
use App\Http\Requests\Catalogue\UpdateEntityRequest;
use App\Http\Resources\SocialSecurityEntityResource;
use App\Models\SocialSecurityEntity;
use App\Support\Database\SchemaConstraint;
use App\Support\Database\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The catalogue of EPS, AFP, ARL and Cajas de Compensación Familiar.
 *
 * No delete endpoint, on purpose. A catalogue entry that is wrong is deactivated,
 * because affiliations from previous years point at it and have to keep resolving.
 */
final class SocialSecurityEntityController extends Controller
{
    public function __construct(
        private readonly ManageCatalogueEntities $catalogue,
    ) {}

    public function index(ListEntitiesRequest $request): JsonResponse
    {
        $perPage = $request->perPage();
        $pattern = $request->searchPattern();
        $status = $request->statusFilter();
        $type = $request->typeFilter();

        $query = SocialSecurityEntity::query()->withCount([
            'affiliations as affiliations_count',
            'activeAffiliations as active_affiliations_count',
        ]);

        if ($pattern !== null) {
            $query->where(function ($q) use ($pattern): void {
                $q->whereRaw("lower(name) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("lower(coalesce(code, '')) LIKE ? ESCAPE '\\'", [$pattern]);
            });
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        $total = (clone $query)->toBase()->getCountForPagination();

        $entities = $query
            ->orderBy('type')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'entities' => SocialSecurityEntityResource::collection($entities->items())->resolve(),
            'pagination' => [
                'total' => $total,
                'per_page' => $entities->perPage(),
                'current_page' => $entities->currentPage(),
                'last_page' => $entities->lastPage(),
                'from' => $entities->firstItem(),
                'to' => $entities->lastItem(),
            ],
            'filters' => [
                'search' => $request->validated('search'),
                'status' => $status,
                'type' => $type?->value,
            ],
        ]);
    }

    public function store(StoreEntityRequest $request): JsonResponse
    {
        try {
            $entity = $this->catalogue->create($request->validated(), $request->user());
        } catch (QueryException $e) {
            // Only the name constraint means "that entity already exists". The code
            // index is deliberately not unique, and an unrelated database failure
            // must not be dressed up as a duplicate.
            if (! UniqueViolation::isFor($e, SchemaConstraint::ENTITY_NAME_PER_TYPE)) {
                throw $e;
            }

            return response()->json([
                'message' => 'Ya existe una entidad de ese tipo con ese nombre.',
                'code' => 'duplicate_entity',
                'errors' => [
                    'name' => ['Ya existe una entidad de ese tipo con ese nombre.'],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'La entidad fue creada correctamente.',
            'entity' => new SocialSecurityEntityResource($entity),
        ], 201);
    }

    public function show(Request $request, SocialSecurityEntity $entity): JsonResponse
    {
        $entity->loadCount([
            'affiliations as affiliations_count',
            'activeAffiliations as active_affiliations_count',
        ]);

        return response()->json([
            'entity' => new SocialSecurityEntityResource($entity),
        ]);
    }

    public function update(UpdateEntityRequest $request, SocialSecurityEntity $entity): JsonResponse
    {
        try {
            $updated = $this->catalogue->update($entity, $request->validated(), $request->user());
        } catch (QueryException $e) {
            if (! UniqueViolation::isFor($e, SchemaConstraint::ENTITY_NAME_PER_TYPE)) {
                throw $e;
            }

            return response()->json([
                'message' => 'Ya existe una entidad de ese tipo con ese nombre.',
                'code' => 'duplicate_entity',
                'errors' => [
                    'name' => ['Ya existe una entidad de ese tipo con ese nombre.'],
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'La entidad fue actualizada correctamente.',
            'entity' => new SocialSecurityEntityResource($updated),
        ]);
    }

    /**
     * Deactivation. Never a delete: history has to keep resolving.
     */
    public function deactivate(Request $request, SocialSecurityEntity $entity): JsonResponse
    {
        try {
            $deactivated = $this->catalogue->deactivate($entity, $request->user());
        } catch (EntityHasOpenAffiliations $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'entity_has_open_affiliations',
                'open_affiliations_count' => $e->openCount,
            ], 409);
        }

        return response()->json([
            'message' => 'La entidad fue desactivada correctamente.',
            'entity' => new SocialSecurityEntityResource($deactivated),
        ]);
    }
}

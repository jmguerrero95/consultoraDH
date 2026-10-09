<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Operations\Actions\CancelNovelty;
use App\Domain\Operations\Actions\ResolveNovelty;
use App\Domain\Operations\NoveltyCategory;
use App\Domain\Operations\NoveltyStatus;
use App\Domain\Operations\OperationNotApplicable;
use App\Http\Controllers\Controller;
use App\Models\ClientNovelty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NoveltyController extends Controller
{
    public function __construct(
        private readonly ResolveNovelty $resolver,
        private readonly CancelNovelty $canceller,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ClientNovelty::query()->with(['client', 'company'])->orderByDesc('created_at');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        $novelties = $query->paginate(min($request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $novelties->map(fn (ClientNovelty $n): array => $this->present($n))->all(),
            'pagination' => [
                'current_page' => $novelties->currentPage(),
                'last_page' => $novelties->lastPage(),
                'per_page' => $novelties->perPage(),
                'total' => $novelties->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'monthly_period_id' => ['nullable', 'integer', 'exists:monthly_periods,id'],
            'contribution_sheet_id' => ['nullable', 'integer', 'exists:contribution_sheets,id'],
            'category' => ['required', 'string', 'in:'.implode(',', array_column(NoveltyCategory::options(), 'value'))],
            'title' => ['required', 'string', 'max:200'],
            'details' => ['nullable', 'string'],
            'occurred_on' => ['nullable', 'date'],
        ]);

        $novelty = ClientNovelty::query()->create($data + [
            'status' => NoveltyStatus::Open->value,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record(AuditAction::NoveltyCreated, $request->user(), [
            'category' => $novelty->category->value,
            'title' => $novelty->title,
        ], null, $novelty);

        return response()->json($this->present($novelty), 201);
    }

    public function resolve(ClientNovelty $novelty, Request $request): JsonResponse
    {
        try {
            $result = $this->resolver->handle($novelty, $request->user());
        } catch (OperationNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 409);
        }

        return response()->json($this->present($result));
    }

    public function cancel(ClientNovelty $novelty, Request $request): JsonResponse
    {
        try {
            $result = $this->canceller->handle($novelty, $request->string('reason')->toString(), $request->user());
        } catch (OperationNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 409);
        }

        return response()->json($this->present($result));
    }

    public function vocabulary(): JsonResponse
    {
        return response()->json([
            'categories' => NoveltyCategory::options(),
            'statuses' => NoveltyStatus::options(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ClientNovelty $novelty): array
    {
        return [
            'id' => (int) $novelty->id,
            'client_id' => (int) $novelty->client_id,
            'client_name' => $novelty->client?->fullName(),
            'company_id' => $novelty->company_id,
            'company_name' => $novelty->company?->legal_name,
            'category' => $novelty->category->value,
            'category_label' => $novelty->category->label(),
            'title' => $novelty->title,
            'details' => $novelty->details,
            'status' => $novelty->status->value,
            'status_label' => $novelty->status->label(),
            'occurred_on' => $novelty->occurred_on?->format('Y-m-d'),
            'resolved_at' => $novelty->resolved_at?->toIso8601String(),
            'created_at' => $novelty->created_at->toIso8601String(),
        ];
    }
}

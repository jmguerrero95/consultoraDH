<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Portal\ProfileUpdateHandler;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientProfileUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff side of a client's profile proposal.
 *
 * §44 / R1: a client proposes, staff decide. Without these routes the permissions
 * `client_update_requests.view` and `client_update_requests.review` were granted to
 * roles and enforced by nothing — a client could submit a change and no ordinary member
 * of staff had any way to act on it.
 *
 * The approval itself lives in the domain and goes through A02's `UpdateClient`; this
 * class only translates HTTP into that call and back.
 */
final class ClientProfileUpdateRequestController extends Controller
{
    public function __construct(private readonly ProfileUpdateHandler $handler) {}

    public function index(Request $request): JsonResponse
    {
        $query = ClientProfileUpdateRequest::query()
            ->with('client')
            ->orderByDesc('created_at');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $requests = $query->paginate(min($request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $requests->map(fn (ClientProfileUpdateRequest $r): array => $this->present($r))->all(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function approve(Request $request, ClientProfileUpdateRequest $updateRequest): JsonResponse
    {
        $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        try {
            $result = $this->handler->approve($updateRequest, $request->user(), $request->input('note'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            // The request moved on, or A02 refused the change. Either way it is not
            // approved, and the client is told what happened.
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_applied'], 409);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'No se pudo aplicar el cambio propuesto.',
                'code' => 'update_refused',
            ], 409);
        }

        return response()->json($this->present($result));
    }

    public function reject(Request $request, ClientProfileUpdateRequest $updateRequest): JsonResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:1000']]);

        try {
            $result = $this->handler->reject($updateRequest, (string) $request->input('note'), $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_applied'], 409);
        }

        return response()->json($this->present($result));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ClientProfileUpdateRequest $r): array
    {
        return [
            'id' => (int) $r->id,
            'client_id' => (int) $r->client_id,
            'client_name' => $r->client?->fullName(),
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            // The proposed values, so a reviewer sees what is being asked for without
            // having to open the client record first.
            'proposed_changes' => $r->proposed_changes,
            'review_note' => $r->review_note,
            'requested_at' => $r->created_at->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'applied_at' => $r->applied_at?->toIso8601String(),
        ];
    }
}

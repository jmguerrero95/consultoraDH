<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Domain\Portal\ProfileUpdateHandler;
use App\Domain\Receivables\ReceivablesService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientDocumentRequest;
use App\Models\ClientProfileUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PortalProfileController extends Controller
{
    public function __construct(
        private readonly ProfileUpdateHandler $profileUpdates,
        private readonly ReceivablesService $receivables,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertClientAccount($user);

        $client = Client::query()->findOrFail($user->client_id);

        return response()->json([
            'id' => (int) $client->id,
            'first_names' => $client->first_names,
            'last_names' => $client->last_names,
            'email' => $client->email,
            'phone' => $client->phone,
            'address' => $client->address,
            'city' => $client->city,
            'department' => $client->department,
            'document_type' => $client->document_type->value,
            'document_number' => $client->document_number,
        ]);
    }

    public function submitUpdateRequest(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertClientAccount($user);

        $data = $request->validate([
            'first_names' => ['nullable', 'string', 'max:100'],
            'last_names' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $changes = array_filter($data, fn ($v) => $v !== null && $v !== '');

        try {
            $updateRequest = $this->profileUpdates->submit(
                Client::query()->findOrFail($user->client_id),
                $changes,
                $user,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => (int) $updateRequest->id,
            'status' => $updateRequest->status->value,
            'proposed_changes' => $updateRequest->proposed_changes,
        ], 201);
    }

    public function updateRequests(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertClientAccount($user);

        $requests = ClientProfileUpdateRequest::query()
            ->where('client_id', $user->client_id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $requests->map(fn (ClientProfileUpdateRequest $r): array => [
                'id' => (int) $r->id,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'proposed_changes' => $r->proposed_changes,
                'review_note' => $r->review_note,
                'created_at' => $r->created_at->toIso8601String(),
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function financialAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertClientAccount($user);

        $client = Client::query()->findOrFail($user->client_id);

        return response()->json($this->receivables->clientAccount($client));
    }

    public function home(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertClientAccount($user);

        $client = Client::query()->findOrFail($user->client_id);

        $pendingRequests = ClientDocumentRequest::query()
            ->where('client_id', $client->id)
            ->where('status', 'requested')
            ->count();

        $visibleDocuments = ClientDocument::query()
            ->where('client_id', $client->id)
            ->where('visibility', 'client')
            ->whereNull('archived_at')
            ->count();

        $openUpdateRequests = ClientProfileUpdateRequest::query()
            ->where('client_id', $client->id)
            ->where('status', 'pending')
            ->count();

        return response()->json([
            'client_name' => $client->fullName(),
            'pending_document_requests' => $pendingRequests,
            'visible_documents' => $visibleDocuments,
            'open_update_requests' => $openUpdateRequests,
        ]);
    }

    private function assertClientAccount($user): void
    {
        abort_unless($user->account_type === 'client', 403, 'Esta sección es solo para clientes.');
    }
}

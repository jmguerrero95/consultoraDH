<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Documents\Actions\CancelDocumentRequest;
use App\Domain\Documents\Actions\DocumentNotApplicable;
use App\Domain\Documents\Actions\RequestDocument;
use App\Domain\Documents\Actions\ReviewDocumentRequest;
use App\Domain\Documents\DocumentFileStore;
use App\Domain\Documents\DocumentRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\ClientDocument;
use App\Models\ClientDocumentRequest;
use App\Models\DocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentController extends Controller
{
    public function __construct(
        private readonly RequestDocument $requester,
        private readonly ReviewDocumentRequest $reviewer,
        private readonly CancelDocumentRequest $canceller,
        private readonly DocumentFileStore $files,
        private readonly AuditRecorder $audit,
    ) {}

    public function indexTypes(Request $request): JsonResponse
    {
        $types = DocumentType::query()->orderBy('name')->get();

        return response()->json([
            'data' => $types->map(fn (DocumentType $t): array => [
                'id' => (int) $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'description' => $t->description,
                'retention_days' => $t->retention_days,
                'client_visible_default' => (bool) $t->client_visible_default,
                'active' => (bool) $t->active,
            ])->all(),
        ]);
    }

    public function storeType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'unique:document_types,slug'],
            'description' => ['nullable', 'string'],
            'retention_days' => ['nullable', 'integer', 'min:1'],
            'client_visible_default' => ['boolean'],
            'active' => ['boolean'],
        ]);

        $type = DocumentType::query()->create($data);

        $this->audit->record(AuditAction::DocumentTypeCreated, $request->user(), ['name' => $type->name], null, $type);

        return response()->json(['id' => (int) $type->id], 201);
    }

    public function indexRequests(Request $request): JsonResponse
    {
        $query = ClientDocumentRequest::query()->with(['client', 'documentType'])->orderByDesc('created_at');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $requests = $query->paginate(min($request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $requests->map(fn (ClientDocumentRequest $r): array => $this->presentRequest($r))->all(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'title' => ['required', 'string', 'max:200'],
            'instructions' => ['nullable', 'string'],
            'due_on' => ['nullable', 'date'],
        ]);

        $docRequest = ClientDocumentRequest::query()->create($data + [
            'status' => DocumentRequestStatus::Requested->value,
            'requested_by' => $request->user()->id,
            'requested_at' => now(),
        ]);

        $this->audit->record(AuditAction::DocumentRequestCreated, $request->user(), [
            'title' => $docRequest->title,
            'client_id' => $docRequest->client_id,
        ], null, $docRequest);

        return response()->json($this->presentRequest($docRequest), 201);
    }

    public function markReceived(ClientDocumentRequest $documentRequest, Request $request): JsonResponse
    {
        try {
            $result = $this->requester->handle($documentRequest, $request->user());
        } catch (DocumentNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        }

        return response()->json($this->presentRequest($result));
    }

    public function reviewRequest(ClientDocumentRequest $documentRequest, Request $request): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approve,reject'],
            'note' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->reviewer->handle($documentRequest, $data['decision'], $data['note'] ?? null, $request->user());
        } catch (DocumentNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        }

        return response()->json($this->presentRequest($result));
    }

    public function cancelRequest(ClientDocumentRequest $documentRequest, Request $request): JsonResponse
    {
        try {
            $result = $this->canceller->handle($documentRequest, $request->string('reason')->toString(), $request->user());
        } catch (DocumentNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        }

        return response()->json($this->presentRequest($result));
    }

    public function indexDocuments(Request $request): JsonResponse
    {
        $query = ClientDocument::query()->with(['client', 'documentType'])->orderByDesc('created_at');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        if ($request->filled('document_type_id')) {
            $query->where('document_type_id', $request->integer('document_type_id'));
        }
        if ($request->filled('review_status')) {
            $query->where('review_status', $request->string('review_status')->toString());
        }

        $documents = $query->paginate(min($request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $documents->map(fn (ClientDocument $d): array => $this->presentDocument($d))->all(),
            'pagination' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'document_request_id' => ['nullable', 'integer', 'exists:client_document_requests,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'visibility' => ['required', 'string', 'in:internal,client'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $upload = $request->file('file');

        try {
            $this->files->assertAllowed($upload);
        } catch (DocumentNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 422);
        }

        $document = ClientDocument::query()->create([
            'client_id' => $request->integer('client_id'),
            'document_type_id' => $request->integer('document_type_id'),
            'document_request_id' => $request->input('document_request_id'),
            'title' => $request->string('title')->toString(),
            'description' => $request->input('description'),
            'visibility' => $request->string('visibility')->toString(),
            'review_status' => 'received',
        ]);

        try {
            $this->files->store($document, $upload, (int) $request->user()->id, false);
        } catch (\Throwable) {
            $document->delete();

            return response()->json(['message' => 'No se pudo guardar el documento.'], 500);
        }

        $this->audit->record(AuditAction::DocumentUploaded, $request->user(), [
            'title' => $document->title,
            'client_id' => $document->client_id,
        ], null, $document);

        return response()->json($this->presentDocument($document), 201);
    }

    public function downloadDocument(ClientDocument $document): StreamedResponse
    {
        $path = $this->files->absolutePath($document);

        if ($path === null) {
            abort(404);
        }

        return response()->streamDownload(function () use ($path): void {
            echo file_get_contents($path);
        }, $document->original_name, [
            'Content-Type' => $document->mime_type,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRequest(ClientDocumentRequest $r): array
    {
        return [
            'id' => (int) $r->id,
            'client_id' => (int) $r->client_id,
            'client_name' => $r->client?->fullName(),
            'document_type_id' => (int) $r->document_type_id,
            'document_type_name' => $r->documentType?->name,
            'title' => $r->title,
            'instructions' => $r->instructions,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'due_on' => $r->due_on?->format('Y-m-d'),
            'requested_at' => $r->requested_at?->toIso8601String(),
            'received_at' => $r->received_at?->toIso8601String(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'approved_at' => $r->approved_at?->toIso8601String(),
            'decision_note' => $r->decision_note,
            'cancelled_at' => $r->cancelled_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDocument(ClientDocument $d): array
    {
        return [
            'id' => (int) $d->id,
            'client_id' => (int) $d->client_id,
            'client_name' => $d->client?->fullName(),
            'document_type_id' => (int) $d->document_type_id,
            'document_type_name' => $d->documentType?->name,
            'title' => $d->title,
            'description' => $d->description,
            'original_name' => $d->original_name,
            'mime_type' => $d->mime_type,
            'size_bytes' => (int) $d->size_bytes,
            'visibility' => $d->visibility->value,
            'review_status' => $d->review_status->value,
            'review_status_label' => $d->review_status->label(),
            'uploaded_via_portal' => (bool) $d->uploaded_via_portal,
            'retention_until' => $d->retention_until?->format('Y-m-d'),
            'archived_at' => $d->archived_at?->toIso8601String(),
            'created_at' => $d->created_at->toIso8601String(),
        ];
    }
}

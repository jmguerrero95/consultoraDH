<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Domain\Documents\Actions\DocumentNotApplicable;
use App\Domain\Documents\Actions\RespondToDocumentRequest;
use App\Domain\Documents\DocumentFileStore;
use App\Domain\Documents\DocumentVisibility;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientDocumentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PortalDocumentController extends Controller
{
    public function __construct(
        private readonly DocumentFileStore $files,
        private readonly RespondToDocumentRequest $responder,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $client = $this->client($request);

        $documents = ClientDocument::query()
            ->where('client_id', $client->id)
            ->where('visibility', 'client')
            ->whereNull('archived_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $documents->map(fn (ClientDocument $d): array => [
                'id' => (int) $d->id,
                'title' => $d->title,
                'document_type_name' => $d->documentType?->name,
                'original_name' => $d->original_name,
                'review_status' => $d->review_status->value,
                'review_status_label' => $d->review_status->label(),
                'created_at' => $d->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function download(ClientDocument $document, Request $request): StreamedResponse
    {
        $client = $this->client($request);

        abort_unless($document->client_id === $client->id, 404);
        abort_unless($document->visibility === DocumentVisibility::Client, 404);
        // An archived document is history, not something to hand out. The id is guessable
        // and the ownership check above would pass for this client's own document, so
        // retention has to be refused here explicitly.
        abort_unless($document->archived_at === null, 404);

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

    public function indexRequests(Request $request): JsonResponse
    {
        $client = $this->client($request);

        $requests = ClientDocumentRequest::query()
            ->where('client_id', $client->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $requests->map(fn (ClientDocumentRequest $r): array => [
                'id' => (int) $r->id,
                'title' => $r->title,
                'instructions' => $r->instructions,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'due_on' => $r->due_on?->format('Y-m-d'),
                'decision_note' => $r->decision_note,
                'created_at' => $r->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function uploadResponse(ClientDocumentRequest $documentRequest, Request $request): JsonResponse
    {
        $user = $request->user();

        $client = $this->client($request);

        // Ownership first, and as a 404 like every other portal reference: the id is a
        // sequential number, so a 403 here would confirm that the request exists.
        abort_unless($documentRequest->client_id === $client->id, 404);

        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        try {
            // The whole lifecycle in one place: claim the request, store the file, move it
            // to `received`, audit. The client does not need a staff member to say that
            // what they sent arrived.
            $document = $this->responder->handle(
                $documentRequest,
                $request->file('file'),
                $user,
            );
        } catch (DocumentNotApplicable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
            ], $e->reason === 'unsafe_upload' ? 422 : 409);
        }

        $documentRequest->refresh();

        return response()->json([
            'id' => (int) $document->id,
            'title' => $document->title,
            'review_status' => $document->review_status->value,
            // The client is told the state the system actually reached, not the one it
            // asked for.
            'request_status' => $documentRequest->status->value,
            'request_status_label' => $documentRequest->status->label(),
        ], 201);
    }

    private function client(Request $request): Client
    {
        $user = $request->user();
        abort_unless($user->account_type === 'client', 403, 'Esta sección es solo para clientes.');

        return Client::query()->findOrFail($user->client_id);
    }
}

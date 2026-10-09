<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Documents\DocumentFileStore;
use App\Domain\Documents\DocumentRequestStatus;
use App\Models\ClientDocument;
use App\Models\ClientDocumentRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * §48 — a client answers a document request, and the request moves on by itself.
 *
 * ## The gap
 *
 * The portal upload created the `ClientDocument` and stopped. The request stayed
 * `requested` until a staff member called `/receive` by hand — so the client's own
 * action left the system showing that nothing had arrived, and the client had to ask
 * somebody to confirm they had sent something. The guard that was supposed to refuse an
 * answer also compared an enum to a string, so it refused every answer.
 *
 * This operation is the whole transaction: check ownership, claim the request, store the
 * file, move the request to `received`, audit.
 *
 * ## Why the request is re-read under lock
 *
 * Ownership and status are decided against the locked row, not against whatever the
 * router bound. Between binding and writing, staff may have received, reviewed or
 * cancelled the request, and an upload that ignored that would attach a document to a
 * request that had already moved on.
 *
 * ## Why the rejection history survives
 *
 * A replacement is a **new** `ClientDocument` row. The previous one is never modified or
 * deleted, and the request's `decision_note` — the reason staff gave — is left alone
 * until the new answer is reviewed. That is what §36 requires: a rejected document stays
 * in history.
 */
final class RespondToDocumentRequest
{
    public function __construct(
        private readonly DocumentFileStore $files,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        ClientDocumentRequest $request,
        UploadedFile $upload,
        User $actor,
    ): ClientDocument {
        $this->files->assertAllowed($upload);

        return DB::transaction(function () use ($request, $upload, $actor): ClientDocument {
            $authoritative = ClientDocumentRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->first();

            if ($authoritative === null || $authoritative->client_id !== $actor->client_id) {
                throw DocumentNotApplicable::wrongState('unknown', 'requested');
            }

            // Only these two accept an answer. `approved` and `cancelled` are terminal, and
            // `received`/`reviewed` already have a document attached.
            if (! in_array(
                $authoritative->status,
                [DocumentRequestStatus::Requested, DocumentRequestStatus::Rejected],
                true,
            )) {
                throw DocumentNotApplicable::wrongState(
                    $authoritative->status->value,
                    DocumentRequestStatus::Received->value,
                );
            }

            $document = ClientDocument::query()->create([
                'client_id' => $authoritative->client_id,
                'document_type_id' => $authoritative->document_type_id,
                'document_request_id' => $authoritative->id,
                'title' => $authoritative->title,
                'visibility' => 'internal',
                'review_status' => 'received',
            ]);

            // The file store deletes the physical file and rethrows if the metadata
            // cannot be persisted, so the transaction above rolls back and leaves no row
            // claiming a file that is not there.
            $this->files->store($document, $upload, (int) $actor->id, true);

            $authoritative->forceFill([
                'status' => DocumentRequestStatus::Received->value,
                'received_at' => now(),
            ])->save();

            $this->audit->record(
                AuditAction::DocumentUploaded,
                $actor,
                [
                    'via' => 'portal',
                    'document_request_id' => (int) $authoritative->id,
                    'replaces_rejection' => $request->status === DocumentRequestStatus::Rejected,
                ],
                null,
                $document,
            );

            $this->audit->record(
                AuditAction::DocumentRequestReceived,
                $actor,
                ['document_id' => (int) $document->id],
                null,
                $authoritative,
            );

            return $document;
        });
    }
}

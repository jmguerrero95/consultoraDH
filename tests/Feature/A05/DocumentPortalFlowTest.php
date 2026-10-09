<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentFileStore;
use App\Domain\Documents\DocumentRequestStatus;
use App\Domain\Planillas\PlanillaFileStore;
use App\Domain\Users\UserStatus;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientDocumentRequest;
use App\Models\ContributionSheet;
use App\Models\ContributionSheetFile;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * A05-R1 §2 — the portal document response has to work end to end.
 *
 * The gap: the guard compared a cast enum against strings, so it refused every answer;
 * and the successful path never moved the request out of `requested`, so a client who
 * uploaded a document still saw an unanswered request until a staff member pressed
 * "recibida" by hand.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    $this->clientA = Client::factory()->create();
    $this->clientB = Client::factory()->create();

    $this->type = DocumentType::factory()->create();

    $this->portal = User::factory()->create([
        'account_type' => 'client',
        'client_id' => $this->clientA->id,
        'status' => UserStatus::Active,
    ]);

    $this->staff = userWithPermissions([
        'documents.view', 'documents.manage', 'documents.request', 'documents.review',
    ]);
});

/** A staff-created request against client A. */
function requestFor($test, $status = DocumentRequestStatus::Requested): ClientDocumentRequest
{
    return ClientDocumentRequest::factory()->create([
        'client_id' => $test->clientA->id,
        'document_type_id' => $test->type->id,
        'status' => $status,
        'title' => 'Cédula de ciudadanía',
        'requested_by' => $test->staff->id,
        'requested_at' => now(),
    ]);
}

function pdf(): UploadedFile
{
    return UploadedFile::fake()->create('documento.pdf', 60, 'application/pdf');
}

// ---------------------------------------------------------------------------
// TEST D1 — the real portal flow
// ---------------------------------------------------------------------------

it('D1: a portal upload moves the request to received on its own', function (): void {
    Storage::fake('documents');

    $request = requestFor($this);

    $response = $this->actingAs($this->portal)
        ->post("/api/portal/document-requests/{$request->id}/upload", [
            'file' => pdf(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('request_status', DocumentRequestStatus::Received->value);

    // Assert the persisted facts.
    $request->refresh();

    expect($request->status)->toBe(DocumentRequestStatus::Received)
        ->and($request->received_at)->not->toBeNull();

    $document = ClientDocument::query()->where('document_request_id', $request->id)->firstOrFail();

    expect((int) $document->client_id)->toBe((int) $this->clientA->id)
        ->and($document->uploaded_via_portal)->toBeTrue();

    // Staff can now review it: the whole point of the transition.
    $this->actingAs($this->staff)
        ->postJson("/api/document-requests/{$request->id}/review", [
            'decision' => 'approve',
            'note' => 'Documento legible',
        ])
        ->assertOk()
        ->assertJsonPath('status', DocumentRequestStatus::Approved->value);
});

it('D1: a client cannot answer a request belonging to another client', function (): void {
    Storage::fake('documents');

    $request = ClientDocumentRequest::factory()->create([
        'client_id' => $this->clientB->id,
        'document_type_id' => $this->type->id,
        'status' => DocumentRequestStatus::Requested,
        'requested_by' => $this->staff->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->portal)
        ->postJson("/api/portal/document-requests/{$request->id}/upload", ['file' => pdf()])
        ->assertNotFound();

    expect(ClientDocument::query()->where('document_request_id', $request->id)->exists())->toBeFalse();
});

it('D1: a request that already arrived refuses a second answer', function (): void {
    Storage::fake('documents');

    $request = requestFor($this, DocumentRequestStatus::Received);
    $request->forceFill(['received_at' => now()])->save();

    $this->actingAs($this->portal)
        ->postJson("/api/portal/document-requests/{$request->id}/upload", ['file' => pdf()])
        ->assertStatus(409);

    expect(ClientDocument::query()->where('document_request_id', $request->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// TEST D2 — a rejected request takes a replacement, and keeps the old document
// ---------------------------------------------------------------------------

it('D2: a rejected request returns to received and the old document survives', function (): void {
    Storage::fake('documents');

    $request = requestFor($this);

    // The first answer is a real upload against this request, so it is attached to it the
    // way a real one would be. Creating it detached and merely *rejecting* it elsewhere
    // would let this test pass even if the answer to this request were being overwritten:
    // the row it checks would never have belonged to the request in the first place.
    $first = $this->actingAs($this->portal)
        ->postJson("/api/portal/document-requests/{$request->id}/upload", ['file' => pdf()])
        ->assertCreated()
        ->assertJsonPath('request_status', DocumentRequestStatus::Received->value)
        ->json();

    $originalDocument = ClientDocument::query()->findOrFail($first['id']);

    expect($originalDocument)->not->toBeNull()
        ->and((int) $originalDocument->document_request_id)->toBe((int) $request->id);

    // The row as it stands before the replacement arrives. The decision itself lives on
    // the request, not on the document — a reviewer records the rejection there — so the
    // claim worth pinning on the document is that answering again leaves it alone.
    $originalBefore = $originalDocument->only([
        'id',
        'stored_path',
        'original_name',
        'review_status',
        'review_note',
        'reviewed_at',
        'reviewed_by',
        'archived_at',
    ]);

    // A real rejection, made through the staff endpoint rather than by forcing a status:
    // the rejection needs its review date and reason, and the database says so.
    $this->actingAs($this->staff)
        ->postJson("/api/document-requests/{$request->id}/review", [
            'decision' => 'reject',
            'note' => 'Documento ilegible',
        ])
        ->assertOk()
        ->assertJsonPath('status', DocumentRequestStatus::Rejected->value);

    $request->refresh();

    expect($request->status)->toBe(DocumentRequestStatus::Rejected);

    // The client answers again, and the request reopens by itself.
    $this->actingAs($this->portal)
        ->postJson("/api/portal/document-requests/{$request->id}/upload", ['file' => pdf()])
        ->assertCreated()
        ->assertJsonPath('request_status', DocumentRequestStatus::Received->value);

    $request->refresh();

    expect($request->status)->toBe(DocumentRequestStatus::Received);

    // Both answers now hang off the same request: the replacement is a NEW row, and the
    // rejected one is untouched — still rejected, still pointing at the file it was
    // reviewed against, still readable by staff.
    $documents = ClientDocument::query()
        ->where('document_request_id', $request->id)
        ->orderBy('id')
        ->get();

    expect($documents)->toHaveCount(2);

    $original = $documents->firstWhere('id', $first['id']);
    $replacement = $documents->firstWhere('id', '!=', $first['id']);

    expect($original)->not->toBeNull('the rejected answer must survive alongside the replacement')
        ->and($original->only(array_keys($originalBefore)))
        ->toBe($originalBefore, 'answering again must not rewrite the earlier answer')
        ->and($replacement)->not->toBeNull()
        ->and((int) $replacement->id)->not->toBe((int) $first['id']);

    // The rejection reason is still on the request until staff reviews the replacement.
    expect($request->decision_note)->toBe('Documento ilegible');
});

// ---------------------------------------------------------------------------
// TEST D3 — an archived document is not downloadable through the portal
// ---------------------------------------------------------------------------

it('D3: an archived document cannot be downloaded by its own client', function (): void {
    Storage::fake('documents');

    $document = ClientDocument::factory()->create([
        'client_id' => $this->clientA->id,
        'document_type_id' => $this->type->id,
        'visibility' => 'client',
        'archived_at' => now(),
    ]);

    Storage::disk('documents')->put($document->stored_path, 'contenido');

    // §47: this is this client's own visible document. Only the archive state stops it,
    // which is exactly what the test is here to prove.
    $this->actingAs($this->portal)
        ->getJson("/api/portal/documents/{$document->id}/download")
        ->assertNotFound();
});

it('D3: the same document downloads normally while it is active', function (): void {
    Storage::fake('documents');

    $document = ClientDocument::factory()->create([
        'client_id' => $this->clientA->id,
        'document_type_id' => $this->type->id,
        'visibility' => 'client',
        'archived_at' => null,
    ]);

    Storage::disk('documents')->put($document->stored_path, 'contenido');

    $this->actingAs($this->portal)
        ->getJson("/api/portal/documents/{$document->id}/download")
        ->assertOk();
});

// ---------------------------------------------------------------------------
// TEST D4 — a staff upload cannot be attached to a mismatched request
// ---------------------------------------------------------------------------

it('D4: refuses a document attached to another client\'s request', function (): void {
    Storage::fake('documents');

    $request = ClientDocumentRequest::factory()->create([
        'client_id' => $this->clientA->id,
        'document_type_id' => $this->type->id,
        'status' => DocumentRequestStatus::Requested,
        'requested_by' => $this->staff->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->staff)
        ->postJson('/api/documents', [
            'client_id' => $this->clientB->id,
            'document_type_id' => $this->type->id,
            'document_request_id' => $request->id,
            'title' => 'Documento de otro cliente',
            'visibility' => 'internal',
            'file' => pdf(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'request_client_mismatch');

    expect(ClientDocument::query()->count())->toBe(0);
});

it('D4: refuses a document attached to a request of another document type', function (): void {
    Storage::fake('documents');

    $otroTipo = DocumentType::factory()->create();

    $request = ClientDocumentRequest::factory()->create([
        'client_id' => $this->clientA->id,
        'document_type_id' => $this->type->id,
        'status' => DocumentRequestStatus::Requested,
        'requested_by' => $this->staff->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->staff)
        ->postJson('/api/documents', [
            'client_id' => $this->clientA->id,
            'document_type_id' => $otroTipo->id,
            'document_request_id' => $request->id,
            'title' => 'Otro tipo',
            'visibility' => 'internal',
            'file' => pdf(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'request_type_mismatch');

    expect(ClientDocument::query()->count())->toBe(0);
});

it('D4: accepts a document whose client and type both match the request', function (): void {
    Storage::fake('documents');

    $request = ClientDocumentRequest::factory()->create([
        'client_id' => $this->clientA->id,
        'document_type_id' => $this->type->id,
        'status' => DocumentRequestStatus::Requested,
        'requested_by' => $this->staff->id,
        'requested_at' => now(),
    ]);

    $this->actingAs($this->staff)
        ->postJson('/api/documents', [
            'client_id' => $this->clientA->id,
            'document_type_id' => $this->type->id,
            'document_request_id' => $request->id,
            'title' => 'Coincide',
            'visibility' => 'internal',
            'file' => pdf(),
        ])
        ->assertCreated();
});

// ---------------------------------------------------------------------------
// File atomicity: bytes first, row second, orphan removed on failure
// ---------------------------------------------------------------------------

it('D5: removes the written file when the metadata cannot be persisted', function (): void {
    Storage::fake('documents');

    $document = ClientDocument::factory()->create([
        'client_id' => $this->clientA->id,
        'document_type_id' => $this->type->id,
    ]);

    expect(Storage::disk('documents')->allFiles())->toBe([]);

    // Make this document's metadata write fail, so the bytes have already been written
    // by the time the failure happens — which is the exact window §66 is about.
    $event = 'eloquent.saving: '.ClientDocument::class;

    Event::listen($event, function (): void {
        throw new RuntimeException('la metadatos no se pudo guardar');
    });

    try {
        expect(fn () => app(DocumentFileStore::class)->store($document, pdf(), (int) $this->staff->id, false))
            ->toThrow(RuntimeException::class, 'la metadatos no se pudo guardar');
    } finally {
        Event::forget($event);
    }

    // The original exception is what reached the caller, and the orphan is gone: nothing
    // in the private tree may be left unaccounted for.
    expect(Storage::disk('documents')->allFiles())->toBe([]);
});

it('D5: removes the written file when a planilla proof metadata write fails', function (): void {
    Storage::fake('planillas');

    $sheet = ContributionSheet::factory()->create();

    expect(Storage::disk('planillas')->allFiles())->toBe([]);

    $event = 'eloquent.creating: '.ContributionSheetFile::class;

    Event::listen($event, function (): void {
        throw new RuntimeException('la metadatos no se pudo guardar');
    });

    try {
        expect(fn () => app(PlanillaFileStore::class)->store(
            $sheet,
            pdf(),
            'operator_pdf',
            (int) $this->staff->id,
        ))->toThrow(RuntimeException::class, 'la metadatos no se pudo guardar');
    } finally {
        Event::forget($event);
    }

    expect(Storage::disk('planillas')->allFiles())->toBe([]);
});

// ---------------------------------------------------------------------------
// A05-R2 — File atomicity: collision-safe physical paths + outer transaction cleanup
// ---------------------------------------------------------------------------

/**
 * F1 — identical document does not corrupt previous upload.
 *
 * Two uploads with identical bytes share the same SHA-256 but MUST NOT share the
 * same physical path. The second upload's metadata failure must not delete the
 * first upload's file.
 */
it('F1: identical document upload failure preserves existing file and metadata', function (): void {
    Storage::fake('documents');

    $request = requestFor($this);

    // First upload succeeds via portal.
    $firstResponse = $this->actingAs($this->portal)
        ->postJson("/api/portal/document-requests/{$request->id}/upload", ['file' => pdf()])
        ->assertCreated()
        ->json();

    $firstDocument = ClientDocument::query()->findOrFail($firstResponse['id']);
    $firstPath = $firstDocument->stored_path;
    $firstSha256 = $firstDocument->sha256;

    expect(Storage::disk('documents')->exists($firstPath))->toBeTrue()
        ->and($firstSha256)->not->toBeEmpty();

    $originalBytes = Storage::disk('documents')->get($firstPath);

    // Second upload with IDENTICAL bytes via STAFF endpoint (different request same client/type).
    // This avoids the portal's "already answered" guard while still testing identical bytes.
    $request2 = requestFor($this);

    $event = 'eloquent.saving: '.ClientDocument::class;

    Event::listen($event, function (): void {
        throw new RuntimeException('la metadatos no se pudo guardar');
    });

    try {
        $this->actingAs($this->staff)
            ->postJson('/api/documents', [
                'client_id' => $this->clientA->id,
                'document_type_id' => $this->type->id,
                'document_request_id' => $request2->id,
                'title' => 'Copia idéntica',
                'visibility' => 'internal',
                'file' => pdf(),
            ])
            ->assertStatus(500);
    } finally {
        Event::forget($event);
    }

    // First document row still exists and is untouched.
    $original = ClientDocument::query()->findOrFail($firstDocument->id);

    expect($original->stored_path)->toBe($firstPath)
        ->and($original->sha256)->toBe($firstSha256);

    // First physical file still exists with unchanged bytes.
    expect(Storage::disk('documents')->exists($firstPath))->toBeTrue()
        ->and(Storage::disk('documents')->get($firstPath))->toBe($originalBytes);

    // No orphan from the failed second upload remains (it would have a different UUID path).
    $allFiles = Storage::disk('documents')->allFiles();
    expect($allFiles)->toHaveCount(1)
        ->and($allFiles[0])->toBe($firstPath);
});

/**
 * F2 — planilla same-content cleanup.
 *
 * Equivalent minimal test for PlanillaFileStore.
 */
it('F2: planilla identical proof upload failure preserves existing proof', function (): void {
    Storage::fake('planillas');

    $sheet = ContributionSheet::factory()->create();

    // First proof succeeds.
    $first = app(PlanillaFileStore::class)->store(
        $sheet,
        pdf(),
        'operator_pdf',
        (int) $this->staff->id,
    );

    $firstPath = $first->stored_path;
    $originalBytes = Storage::disk('planillas')->get($firstPath);

    // Second proof with IDENTICAL bytes — force its metadata create to fail AFTER
    // the bytes are written.
    $event = 'eloquent.creating: '.ContributionSheetFile::class;

    Event::listen($event, function (): void {
        throw new RuntimeException('la metadatos no se pudo guardar');
    });

    try {
        expect(fn () => app(PlanillaFileStore::class)->store(
            $sheet,
            pdf(),
            'operator_pdf',
            (int) $this->staff->id,
        ))->toThrow(RuntimeException::class, 'la metadatos no se pudo guardar');
    } finally {
        Event::forget($event);
    }

    // First proof row still exists.
    $original = ContributionSheetFile::query()->findOrFail($first->id);

    expect($original->stored_path)->toBe($firstPath)
        ->and($original->sha256)->toBe($first->sha256);

    // First physical file still exists with unchanged bytes.
    expect(Storage::disk('planillas')->exists($firstPath))->toBeTrue()
        ->and(Storage::disk('planillas')->get($firstPath))->toBe($originalBytes);

    // No orphan from the failed second upload remains.
    expect(Storage::disk('planillas')->allFiles())->toHaveCount(1)
        ->and(Storage::disk('planillas')->allFiles()[0])->toBe($firstPath);
});

/**
 * F3 — portal outer transaction rollback cleanup.
 *
 * Uses real RespondToDocumentRequest. Forces a failure AFTER DocumentFileStore::store()
 * succeeds but BEFORE the outer business transaction commits.
 */
it('F3: portal outer transaction rollback removes only the newly written file', function (): void {
    Storage::fake('documents');

    $request = requestFor($this);

    // First, upload a valid document so the request is in Received state.
    $firstResponse = $this->actingAs($this->portal)
        ->postJson("/api/portal/document-requests/{$request->id}/upload", ['file' => pdf()])
        ->assertCreated()
        ->json();

    $firstDocument = ClientDocument::query()->findOrFail($firstResponse['id']);
    $firstPath = $firstDocument->stored_path;

    expect(Storage::disk('documents')->exists($firstPath))->toBeTrue();

    // Now reject it so we can test the replacement flow.
    $this->actingAs($this->staff)
        ->postJson("/api/document-requests/{$request->id}/review", [
            'decision' => 'reject',
            'note' => 'Ilegible',
        ])
        ->assertOk();

    // Force a failure AFTER the file store succeeds but BEFORE the transaction commits.
    // We listen to the 'saved' event on ClientDocumentRequest which fires inside the
    // transaction. Throwing here causes the transaction to roll back, triggering the
    // outer catch in RespondToDocumentRequest which deletes the new file.
    $failOnSave = true;

    ClientDocumentRequest::saved(function ($model) use (&$failOnSave): void {
        if ($failOnSave) {
            $failOnSave = false; // only once
            throw new RuntimeException('forzada: fallo tras guardar archivo');
        }
    });

    try {
        $this->actingAs($this->portal)
            ->postJson("/api/portal/document-requests/{$request->id}/upload", ['file' => pdf()])
            ->assertStatus(500);
    } finally {
        $failOnSave = false;
    }

    // Request remains in Rejected (original state before this upload attempt).
    $request->refresh();
    expect($request->status)->toBe(DocumentRequestStatus::Rejected);

    // No ClientDocument row was created for the failed attempt.
    $documents = ClientDocument::query()->where('document_request_id', $request->id)->get();
    expect($documents)->toHaveCount(1)
        ->and($documents->first()->id)->toBe($firstDocument->id);

    // The ORIGINAL file still exists.
    expect(Storage::disk('documents')->exists($firstPath))->toBeTrue();

    // NO new orphan file remains (the failed upload's UUID file was deleted).
    $allFiles = Storage::disk('documents')->allFiles();
    expect($allFiles)->toHaveCount(1)
        ->and($allFiles[0])->toBe($firstPath);
});

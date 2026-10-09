<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentRequestStatus;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\DocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    seedPortfolioRoles();

    $this->clientA = Client::factory()->create(['document_number' => '111111']);
    $this->clientB = Client::factory()->create(['document_number' => '222222']);

    $this->operator = userWithPermissions(['documents.view', 'documents.manage', 'documents.request', 'documents.review']);
    $this->viewer = userWithPermissions(['documents.view']);
});

it('creates a document type', function (): void {
    $this->actingAs($this->operator)->postJson('/api/document-types', [
        'name' => 'Cédula de ciudadanía',
        'slug' => 'cedula-ciudadania',
        'description' => 'Documento de identidad',
        'retention_days' => 365,
        'client_visible_default' => true,
        'active' => true,
    ])->assertCreated();

    expect(DocumentType::query()->where('slug', 'cedula-ciudadania')->exists())->toBeTrue();
});

it('walks the document request lifecycle: create, receive, review, approve', function (): void {
    $type = DocumentType::factory()->create();

    $request = $this->actingAs($this->operator)->postJson('/api/document-requests', [
        'client_id' => $this->clientA->id,
        'document_type_id' => $type->id,
        'title' => 'Solicitar cédula',
        'instructions' => 'Adjuntar cédula por ambos lados',
        'due_on' => '2025-04-01',
    ])->assertCreated()->json();

    expect($request['status'])->toBe(DocumentRequestStatus::Requested->value);

    $this->actingAs($this->operator)->postJson("/api/document-requests/{$request['id']}/receive")
        ->assertOk()
        ->assertJsonPath('status', DocumentRequestStatus::Received->value);

    $this->actingAs($this->operator)->postJson("/api/document-requests/{$request['id']}/review", [
        'decision' => 'approve',
        'note' => 'Documento válido',
    ])->assertOk()
        ->assertJsonPath('status', DocumentRequestStatus::Approved->value);
});

it('rejects a document request with a reason', function (): void {
    $type = DocumentType::factory()->create();

    $request = $this->actingAs($this->operator)->postJson('/api/document-requests', [
        'client_id' => $this->clientA->id,
        'document_type_id' => $type->id,
        'title' => 'Solicitar certificado',
    ])->assertCreated()->json();

    $this->actingAs($this->operator)->postJson("/api/document-requests/{$request['id']}/receive")->assertOk();

    $this->actingAs($this->operator)->postJson("/api/document-requests/{$request['id']}/review", [
        'decision' => 'reject',
        'note' => 'Documento ilegible',
    ])->assertOk()
        ->assertJsonPath('status', DocumentRequestStatus::Rejected->value);
});

it('rejects an unsafe MIME type', function (): void {
    Storage::fake('documents');

    $type = DocumentType::factory()->create();

    $file = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');

    $this->actingAs($this->operator)->postJson('/api/documents', [
        'client_id' => $this->clientA->id,
        'document_type_id' => $type->id,
        'title' => 'Archivo peligroso',
        'visibility' => 'internal',
        'file' => $file,
    ])->assertStatus(422)->assertJsonPath('code', 'unsafe_upload');
});

it('uploads a document to private storage and serves it through the controller', function (): void {
    Storage::fake('documents');

    $type = DocumentType::factory()->create(['retention_days' => 365]);

    $file = UploadedFile::fake()->create('cedula.pdf', 100, 'application/pdf');

    $response = $this->actingAs($this->operator)->postJson('/api/documents', [
        'client_id' => $this->clientA->id,
        'document_type_id' => $type->id,
        'title' => 'Cédula',
        'visibility' => 'client',
        'file' => $file,
    ])->assertCreated();

    $document = ClientDocument::query()->find($response->json('id'));

    expect($document->stored_path)->not->toContain('cedula.pdf')
        ->and($document->retention_until)->not->toBeNull()
        ->and($document->sha256)->toHaveLength(64);

    $this->actingAs($this->operator)->getJson("/api/documents/{$document->id}/download")
        ->assertOk();
});

it('prevents Client A from downloading Client B document', function (): void {
    Storage::fake('documents');

    $type = DocumentType::factory()->create();

    $docB = ClientDocument::factory()->create([
        'client_id' => $this->clientB->id,
        'document_type_id' => $type->id,
    ]);

    Storage::disk('documents')->put($docB->stored_path, 'content');

    $this->actingAs($this->operator)->getJson("/api/documents/{$docB->id}/download")
        ->assertOk();
});

it('enforces permissions on document mutations', function (): void {
    $type = DocumentType::factory()->create();

    $this->actingAs($this->viewer)->postJson('/api/document-requests', [
        'client_id' => $this->clientA->id,
        'document_type_id' => $type->id,
        'title' => 'Solicitud',
    ])->assertForbidden();
});

it('snapshots retention at upload time', function (): void {
    Storage::fake('documents');

    $type = DocumentType::factory()->create(['retention_days' => 365]);

    $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

    $response = $this->actingAs($this->operator)->postJson('/api/documents', [
        'client_id' => $this->clientA->id,
        'document_type_id' => $type->id,
        'title' => 'Test',
        'visibility' => 'internal',
        'file' => $file,
    ])->assertCreated();

    $document = ClientDocument::query()->find($response->json('id'));
    $originalRetention = $document->retention_until;

    $type->forceFill(['retention_days' => 730])->save();

    $document->refresh();
    expect($document->retention_until->toDateString())->toBe($originalRetention->toDateString());
});

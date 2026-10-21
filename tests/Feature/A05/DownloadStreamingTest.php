<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Company;
use App\Models\ContributionSheet;
use App\Models\DocumentType;
use App\Models\MonthlyPeriod;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    seedPortfolioRoles();

    $this->operator = userWithPermissions(['documents.view', 'planillas.view']);
    $this->client = Client::factory()->create();
});

it('streams a large private document with private cache headers', function (): void {
    Storage::fake('documents');

    $type = DocumentType::factory()->create();
    $document = ClientDocument::factory()->create([
        'client_id' => $this->client->id,
        'document_type_id' => $type->id,
        'original_name' => 'soporte.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $payload = str_repeat('0123456789abcdef', 256 * 1024); // 4 MiB
    Storage::disk('documents')->put($document->stored_path, $payload);

    $response = $this->actingAs($this->operator)
        ->get("/api/documents/{$document->id}/download")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('x-content-type-options', 'nosniff');

    expect((string) $response->headers->get('cache-control'))
        ->toContain('private')
        ->toContain('no-store')
        ->and(strlen($response->streamedContent()))->toBe(strlen($payload))
        ->and(hash('sha256', $response->streamedContent()))->toBe(hash('sha256', $payload));
});

it('returns 404 instead of reading a missing private document', function (): void {
    Storage::fake('documents');

    $type = DocumentType::factory()->create();
    $document = ClientDocument::factory()->create([
        'client_id' => $this->client->id,
        'document_type_id' => $type->id,
    ]);

    $this->actingAs($this->operator)
        ->get("/api/documents/{$document->id}/download")
        ->assertNotFound();
});

it('streams generated planilla exports as private no-store responses', function (): void {
    $period = MonthlyPeriod::factory()->create();
    $company = Company::factory()->create();
    $sheet = ContributionSheet::factory()->create([
        'monthly_period_id' => $period->id,
        'company_id' => $company->id,
    ]);

    foreach (['xlsx', 'pdf'] as $format) {
        $response = $this->actingAs($this->operator)
            ->get("/api/planillas/{$sheet->id}/export/{$format}")
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff');

        expect((string) $response->headers->get('cache-control'))
            ->toContain('private')
            ->toContain('no-store')
            ->and(strlen($response->streamedContent()))->toBeGreaterThan(0);
    }
});

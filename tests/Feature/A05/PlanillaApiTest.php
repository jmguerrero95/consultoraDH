<?php

declare(strict_types=1);

use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\PlanillaOperator;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\ContributionSheet;
use App\Models\ContributionSheetFile;
use App\Models\MonthlyPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    seedPortfolioRoles();

    $this->period = MonthlyPeriod::factory()->create(['period_month' => '2025-03-01']);
    $this->company = Company::factory()->create(['tax_id' => '900123456', 'legal_name' => 'ANDINA S.A.S.']);

    $this->client = Client::factory()->create(['document_type' => 'CC', 'document_number' => '10101010']);
    ClientCompanyAssignment::factory()->create([
        'client_id' => $this->client->id,
        'company_id' => $this->company->id,
        'started_on' => '2025-01-10',
    ]);

    $this->operator = userWithPermissions(['planillas.view', 'planillas.create', 'planillas.validate', 'planillas.submit', 'planillas.mark_paid', 'planillas.cancel', 'planillas.update']);
});

it('previews candidates without creating a planilla', function (): void {
    $response = $this->actingAs($this->operator)->postJson('/api/planillas/preview', [
        'period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'operator' => PlanillaOperator::Simple->value,
    ]);

    $response->assertOk()
        ->assertJsonPath('candidate_count', 1)
        ->assertJsonPath('candidates.0.document_number', '10101010');

    expect(ContributionSheet::query()->count())->toBe(0);
});

it('creates a planilla from a valid preview digest', function (): void {
    $preview = $this->actingAs($this->operator)->postJson('/api/planillas/preview', [
        'period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'operator' => PlanillaOperator::Simple->value,
    ])->json();

    $response = $this->actingAs($this->operator)->postJson('/api/planillas', [
        'period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'operator' => PlanillaOperator::Simple->value,
        'source_digest' => $preview['source_digest'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('status', ContributionSheetStatus::Draft->value)
        ->assertJsonPath('line_count', 1)
        ->assertJsonPath('total_liquidated_cop', 0);
});

it('rejects a stale preview digest with 409', function (): void {
    $preview = $this->actingAs($this->operator)->postJson('/api/planillas/preview', [
        'period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'operator' => PlanillaOperator::Simple->value,
    ])->json();

    $second = Client::factory()->create(['document_type' => 'CC', 'document_number' => '20202020']);
    ClientCompanyAssignment::factory()->create([
        'client_id' => $second->id,
        'company_id' => $this->company->id,
        'started_on' => '2025-02-01',
    ]);

    $response = $this->actingAs($this->operator)->postJson('/api/planillas', [
        'period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'operator' => PlanillaOperator::Simple->value,
        'source_digest' => $preview['source_digest'],
    ]);

    $response->assertStatus(409)->assertJsonPath('reason', 'preview_stale');
});

it('walks the full lifecycle: validate, submit, mark paid', function (): void {
    $sheet = ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'operator' => PlanillaOperator::Simple->value,
    ]);

    $line = $sheet->lines()->create([
        'client_id' => $this->client->id,
        'client_company_assignment_id' => ClientCompanyAssignment::query()->first()->id,
        'document_type' => 'CC',
        'document_number' => '10101010',
        'client_name' => 'TEST CLIENT',
        'company_tax_id' => '900123456',
        'company_name' => 'ANDINA S.A.S.',
        'relationship_started_on' => '2025-01-10',
        'relationship_started_on_precision' => 'day',
        'relationship_ended_on' => null,
        'relationship_ended_on_precision' => null,
        'eps_name' => 'EPS TEST',
        'afp_name' => 'AFP TEST',
        'arl_name' => 'ARL TEST',
        'ccf_name' => 'CCF TEST',
        'arl_risk_class' => '1',
        'job_title' => 'Analista',
        'liquidated_amount_cop' => 1_000_000,
        'included' => true,
        'exclusion_reason' => null,
        'source_evidence' => null,
    ]);

    $this->actingAs($this->operator)->postJson("/api/planillas/{$sheet->id}/validate")
        ->assertOk()
        ->assertJsonPath('valid', true);

    $sheet->refresh();
    expect($sheet->status)->toBe(ContributionSheetStatus::Ready);

    $this->actingAs($this->operator)->postJson("/api/planillas/{$sheet->id}/submit", [
        'reference' => 'REF-001',
        'submitted_on' => '2025-04-05',
    ])->assertOk();

    $sheet->refresh();
    expect($sheet->status)->toBe(ContributionSheetStatus::Submitted);

    Storage::fake('planillas');
    $file = UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf');

    $this->actingAs($this->operator)->postJson("/api/planillas/{$sheet->id}/files", [
        'file' => $file,
        'kind' => 'payment_receipt',
    ])->assertCreated();

    $this->actingAs($this->operator)->postJson("/api/planillas/{$sheet->id}/mark-paid", [
        'paid_on' => '2025-04-10',
    ])->assertOk();

    $sheet->refresh();
    expect($sheet->status)->toBe(ContributionSheetStatus::Paid);
});

it('refuses to mark paid without proof', function (): void {
    $sheet = ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'operator' => PlanillaOperator::Simple->value,
        'status' => ContributionSheetStatus::Submitted,
        'submitted_on' => '2025-04-05',
    ]);

    $this->actingAs($this->operator)->postJson("/api/planillas/{$sheet->id}/mark-paid", [
        'paid_on' => '2025-04-10',
    ])->assertStatus(409)->assertJsonPath('reason', 'missing_payment_proof');
});

it('enforces permissions on every mutation', function (): void {
    $viewer = userWithPermissions(['planillas.view']);

    $this->actingAs($viewer)->postJson('/api/planillas/preview', [
        'period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'operator' => PlanillaOperator::Simple->value,
    ])->assertForbidden();

    $this->actingAs($viewer)->postJson('/api/planillas', [
        'period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'operator' => PlanillaOperator::Simple->value,
        'source_digest' => str_repeat('a', 64),
    ])->assertForbidden();
});

it('exports a planilla as xlsx and pdf', function (): void {
    $sheet = ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'operator' => PlanillaOperator::Simple->value,
    ]);

    $this->actingAs($this->operator)->getJson("/api/planillas/{$sheet->id}/export/xlsx")
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($this->operator)->getJson("/api/planillas/{$sheet->id}/export/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('serves planilla files only through the authorised controller', function (): void {
    Storage::fake('planillas');

    $sheet = ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'operator' => PlanillaOperator::Simple->value,
    ]);

    $relativePath = 'sheets/test-file.pdf';
    Storage::disk('planillas')->put($relativePath, 'fake-pdf-content');

    $file = ContributionSheetFile::factory()->create([
        'contribution_sheet_id' => $sheet->id,
        'kind' => 'operator_pdf',
        'stored_path' => $relativePath,
    ]);

    $this->actingAs($this->operator)->getJson("/api/planillas/{$sheet->id}/files/{$file->id}")
        ->assertOk();

    $viewer = userWithPermissions(['novelties.view']);
    $this->actingAs($viewer)->getJson("/api/planillas/{$sheet->id}/files/{$file->id}")
        ->assertForbidden();
});

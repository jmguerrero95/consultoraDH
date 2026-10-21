<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientDocumentRequest;
use App\Models\ClientNovelty;
use App\Models\Company;
use App\Models\ContributionSheet;
use App\Models\OperationalTask;

beforeEach(function (): void {
    seedPortfolioRoles();

    $this->operator = userWithPermissions(['tasks.view', 'tasks.manage']);

    $this->clientA = Client::factory()->create(['document_number' => '90000001']);
    $this->clientB = Client::factory()->create(['document_number' => '90000002']);

    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();

    ClientCompanyAssignment::factory()->create([
        'client_id' => $this->clientA->id,
        'company_id' => $this->companyA->id,
    ]);

    ClientCompanyAssignment::factory()->create([
        'client_id' => $this->clientB->id,
        'company_id' => $this->companyB->id,
    ]);
});

it('rejects a novelty that belongs to another client', function (): void {
    $novelty = ClientNovelty::factory()->create(['client_id' => $this->clientB->id]);

    $this->actingAs($this->operator)->postJson('/api/tasks', [
        'client_id' => $this->clientA->id,
        'novelty_id' => $novelty->id,
        'title' => 'Gestionar novedad',
        'assigned_to' => $this->operator->id,
        'priority' => 'normal',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'subject_client_mismatch');

    expect(OperationalTask::query()->count())->toBe(0);
});

it('rejects a document request that belongs to another client', function (): void {
    $request = ClientDocumentRequest::factory()->create(['client_id' => $this->clientB->id]);

    $this->actingAs($this->operator)->postJson('/api/tasks', [
        'client_id' => $this->clientA->id,
        'document_request_id' => $request->id,
        'title' => 'Solicitar documento',
        'assigned_to' => $this->operator->id,
        'priority' => 'normal',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'subject_client_mismatch');

    expect(OperationalTask::query()->count())->toBe(0);
});

it('rejects a planilla from a company that is not assigned to the client', function (): void {
    $sheet = ContributionSheet::factory()->create([
        'company_id' => $this->companyB->id,
    ]);

    $this->actingAs($this->operator)->postJson('/api/tasks', [
        'client_id' => $this->clientA->id,
        'contribution_sheet_id' => $sheet->id,
        'title' => 'Revisar planilla',
        'assigned_to' => $this->operator->id,
        'priority' => 'normal',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'subject_client_mismatch');

    expect(OperationalTask::query()->count())->toBe(0);
});

it('rejects two references that name different clients when no client is given', function (): void {
    $novelty = ClientNovelty::factory()->create(['client_id' => $this->clientA->id]);
    $request = ClientDocumentRequest::factory()->create(['client_id' => $this->clientB->id]);

    $this->actingAs($this->operator)->postJson('/api/tasks', [
        'novelty_id' => $novelty->id,
        'document_request_id' => $request->id,
        'title' => 'Tarea contradictoria',
        'assigned_to' => $this->operator->id,
        'priority' => 'normal',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'subject_client_mismatch');

    expect(OperationalTask::query()->count())->toBe(0);
});

it('requires a subject or a description instead of reaching the database check', function (): void {
    $this->actingAs($this->operator)->postJson('/api/tasks', [
        'title' => 'Tarea sin asunto',
        'assigned_to' => $this->operator->id,
        'priority' => 'normal',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('description');

    expect(OperationalTask::query()->count())->toBe(0);
});

it('accepts a description as the subject of a global task', function (): void {
    $this->actingAs($this->operator)->postJson('/api/tasks', [
        'title' => 'Preparar el cierre mensual',
        'description' => 'Revisar los borradores antes del viernes.',
        'assigned_to' => $this->operator->id,
        'priority' => 'normal',
    ])->assertCreated();

    expect(OperationalTask::query()->count())->toBe(1);
});

it('accepts a client, novelty and planilla that agree', function (): void {
    $novelty = ClientNovelty::factory()->create(['client_id' => $this->clientA->id]);
    $sheet = ContributionSheet::factory()->create(['company_id' => $this->companyA->id]);

    $this->actingAs($this->operator)->postJson('/api/tasks', [
        'client_id' => $this->clientA->id,
        'novelty_id' => $novelty->id,
        'contribution_sheet_id' => $sheet->id,
        'title' => 'Gestionar novedad y planilla',
        'assigned_to' => $this->operator->id,
        'priority' => 'normal',
    ])->assertCreated();

    expect(OperationalTask::query()->count())->toBe(1);
});

it('accepts a planilla whose company is assigned to the referenced client', function (): void {
    $novelty = ClientNovelty::factory()->create(['client_id' => $this->clientA->id]);
    $sheet = ContributionSheet::factory()->create(['company_id' => $this->companyA->id]);

    $this->actingAs($this->operator)->postJson('/api/tasks', [
        'novelty_id' => $novelty->id,
        'contribution_sheet_id' => $sheet->id,
        'title' => 'Tarea derivada de la novedad',
        'assigned_to' => $this->operator->id,
        'priority' => 'normal',
    ])->assertCreated();

    expect(OperationalTask::query()->count())->toBe(1);
});

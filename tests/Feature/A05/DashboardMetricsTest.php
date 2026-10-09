<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\ClientDocumentRequest;
use App\Models\ClientProfileUpdateRequest;
use App\Models\Company;
use App\Models\ContributionSheet;
use App\Models\DocumentType;
use App\Models\MonthlyPeriod;
use App\Models\OperationalTask;

beforeEach(function (): void {
    seedPortfolioRoles();

    $this->company = Company::factory()->create();
    $this->period = MonthlyPeriod::factory()->create(['period_month' => '2025-03-01']);
    $this->client = Client::factory()->create();
});

it('omits an A05 metric the caller may not read, rather than reporting zero', function (): void {
    // §58: "A role lacking a permission gets no key/section, not zero." A zero would say
    // there is nothing pending, which is a different and wrong statement.
    $contable = userWithPermissions(['novelties.view']);

    $payload = $this->actingAs($contable)->getJson('/api/dashboard')->assertOk()->json();

    expect($payload['operations']['visible'])->toBeTrue()
        ->and($payload['operations'])->not->toHaveKey('counts')
        ->and($payload['operations'])->not->toHaveKey('planillas_draft');
});

it('publishes the A05 metric only to a caller who may read it', function (): void {
    ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'status' => 'draft',
    ]);

    ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'status' => 'submitted',
        'submitted_on' => '2025-04-05',
    ]);

    $operador = userWithPermissions(['planillas.view']);

    $counts = $this->actingAs($operador)->getJson('/api/dashboard')->assertOk()->json('operations.counts');

    expect($counts['planillas_draft'])->toBe(1)
        ->and($counts['planillas_submitted_unpaid'])->toBe(1);
});

it('counts a task due today as not overdue, and one past its date as overdue', function (): void {
    $this->operator = userWithPermissions(['tasks.manage']);

    OperationalTask::factory()->create(['due_on' => now()->toDateString(), 'status' => 'pending']);
    OperationalTask::factory()->create([
        'due_on' => now()->subDay()->toDateString(),
        'status' => 'pending',
    ]);
    // A completed task is history and is not overdue work. It carries `completed_at`
    // because the database refuses a `done` row without one (§70).
    OperationalTask::factory()->create([
        'due_on' => now()->subYear()->toDateString(),
        'status' => 'done',
        'completed_at' => now(),
        'completed_by' => $this->operator->id ?? null,
    ]);

    $counts = $this->actingAs(userWithPermissions(['tasks.view']))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('operations.counts');

    // The done one is history and not counted; today is not late.
    expect($counts['tasks_overdue'])->toBe(1);
});

it('counts pending document requests and profile update requests for the roles that read them', function (): void {
    $type = DocumentType::factory()->create();

    ClientDocumentRequest::factory()->create([
        'client_id' => $this->client->id,
        'document_type_id' => $type->id,
        'status' => 'requested',
    ]);

    ClientProfileUpdateRequest::factory()->create([
        'client_id' => $this->client->id,
        'status' => 'pending',
    ]);

    $counts = $this->actingAs(userWithPermissions(['documents.view', 'client_update_requests.view']))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('operations.counts');

    expect($counts['document_requests_open'])->toBe(1)
        ->and($counts['profile_update_requests_pending'])->toBe(1);
});

it('keeps the A02 and A03 dashboard arithmetic untouched', function (): void {
    $counts = $this->actingAs(userWithPermissions(['clients.view', 'receivables.view', 'planillas.view']))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json();

    expect($counts['portfolio']['visible'])->toBeTrue()
        ->and($counts['portfolio']['counts'])->toHaveKey('active_clients')
        ->and($counts['operations']['counts'])->toHaveKey('planillas_draft');
});

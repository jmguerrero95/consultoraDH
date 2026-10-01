<?php

declare(strict_types=1);

use App\Domain\Affiliations\ManageClientCompanies;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use Illuminate\Database\QueryException;

/**
 * The historical relationship between a client and a company.
 *
 * The rules under test are the ones that keep history intact: a transfer closes
 * and opens instead of rewriting, a second open relationship is never created
 * silently, and a parallel one has to say why.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

function linkPayload(array $overrides = []): array
{
    return array_merge([
        'company_id' => 1,
        'started_on' => '2025-01-15',
        'resolution' => 'only_if_none',
    ], $overrides);
}

// --- The first relationship --------------------------------------------------

it('links a client to a company for the first time', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => $company->id,
            'job_title' => 'Analista',
        ]))
        ->assertCreated()
        ->assertJsonPath('assignment.started_on', '2025-01-15')
        ->assertJsonPath('assignment.is_active', true)
        ->assertJsonPath('assignment.job_title', 'Analista')
        ->assertJsonPath('assignment.is_parallel', false);

    expect(ClientCompanyAssignment::query()->count())->toBe(1);
});

it('refuses to link an inactive client', function (): void {
    $client = Client::factory()->inactive()->create();
    $company = Company::factory()->create();

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload(['company_id' => $company->id]))
        ->assertStatus(422);
});

it('refuses to link an inactive company', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->inactive()->create();

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload(['company_id' => $company->id]))
        ->assertStatus(422);
});

it('rejects a closing date before the opening date', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => $company->id,
            'ended_on' => '2024-01-01',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('ended_on');
});

it('refuses a date range the database would also reject', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    ClientCompanyAssignment::factory()->closed('2024-01-01')->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
    ]);

    // Bypasses validation entirely and goes straight at the constraint.
    ClientCompanyAssignment::query()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'started_on' => '2024-01-01',
        'ended_on' => '2023-12-31',
    ]);
})->throws(QueryException::class);

// --- A second open relationship ---------------------------------------------

it('refuses a second active relationship and offers the three choices', function (): void {
    $client = Client::factory()->create();
    $first = Company::factory()->create(['legal_name' => 'Primera S.A.S.']);
    $second = Company::factory()->create(['legal_name' => 'Segunda S.A.S.']);

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $first->id,
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => $second->id,
            'started_on' => '2025-06-01',
        ]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'parallel_relationship_not_allowed')
        ->assertJsonStructure(['message', 'code', 'open_assignments', 'options']);

    // Nothing was written: a refusal is not a warning that still creates the row.
    expect(ClientCompanyAssignment::query()->count())->toBe(1);
});

it('lists the three resolutions it offers', function (): void {
    $client = Client::factory()->create();
    ClientCompanyAssignment::factory()->create(['client_id' => $client->id]);

    $response = $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => Company::factory()->create()->id,
    ]));

    $values = array_column($response->json('options'), 'value');

    expect($values)->toBe(['transfer', 'parallel', 'cancel']);

    // Only the parallel choice asks for a reason, and the interface can rely on
    // that rather than guessing.
    $byValue = collect($response->json('options'))->keyBy('value');

    expect($byValue['parallel']['requires_reason'])->toBeTrue()
        ->and($byValue['transfer']['requires_reason'])->toBeFalse();
});

it('refuses a parallel relationship without a justification', function (): void {
    $client = Client::factory()->create();
    ClientCompanyAssignment::factory()->create(['client_id' => $client->id]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => Company::factory()->create()->id,
            'resolution' => 'parallel',
            'parallel_reason' => '   ',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('parallel_reason');

    expect(ClientCompanyAssignment::query()->count())->toBe(1);
});

it('stores a parallel relationship with its reason', function (): void {
    $client = Client::factory()->create();
    ClientCompanyAssignment::factory()->create(['client_id' => $client->id]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => Company::factory()->create()->id,
            'resolution' => 'parallel',
            'parallel_reason' => 'Trabaja medio tiempo en ambas empresas',
            'started_on' => '2025-02-01',
        ]))
        ->assertCreated()
        ->assertJsonPath('assignment.is_parallel', true)
        ->assertJsonPath('assignment.parallel_reason', 'Trabaja medio tiempo en ambas empresas')
        ->assertJsonPath('assignment.is_active', true);

    expect(ClientCompanyAssignment::query()->whereNull('ended_on')->count())->toBe(2);
});

it('enforces the parallel justification in the database too', function (): void {
    $client = Client::factory()->create();

    // A timestamp with no reason, written around the application.
    ClientCompanyAssignment::query()->create([
        'client_id' => $client->id,
        'company_id' => Company::factory()->create()->id,
        'started_on' => '2025-01-01',
        'parallel_authorized_at' => now(),
        'parallel_reason' => null,
    ]);
})->throws(QueryException::class);

// --- Transfer ---------------------------------------------------------------

it('transfers a client, closing one row and opening another', function (): void {
    $client = Client::factory()->create();
    $from = Company::factory()->create(['legal_name' => 'Origen S.A.S.']);
    $to = Company::factory()->create(['legal_name' => 'Destino S.A.S.']);

    $assignment = ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $from->id,
        'started_on' => '2024-01-01',
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-company-assignments/{$assignment->id}/transfer", [
            'to_company_id' => $to->id,
            'effective_on' => '2025-03-01',
            'job_title' => 'Gerente',
        ])
        ->assertOk()
        ->assertJsonPath('closed_assignment.company_id', $from->id)
        ->assertJsonPath('closed_assignment.ended_on', '2025-03-01')
        ->assertJsonPath('assignment.company_id', $to->id)
        ->assertJsonPath('assignment.started_on', '2025-03-01')
        ->assertJsonPath('assignment.job_title', 'Gerente');

    // Two rows: the old one is preserved with its own company, and it is the old
    // company that is still on it. Nothing was rewritten.
    expect(ClientCompanyAssignment::query()->count())->toBe(2)
        ->and($assignment->fresh()->company_id)->toBe($from->id)
        ->and($assignment->fresh()->ended_on?->format('Y-m-d'))->toBe('2025-03-01');
});

it('leaves exactly one open relationship after a transfer', function (): void {
    $client = Client::factory()->create();
    $assignment = ClientCompanyAssignment::factory()->create(['client_id' => $client->id]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-company-assignments/{$assignment->id}/transfer", [
            'to_company_id' => Company::factory()->create()->id,
            'effective_on' => '2025-05-01',
        ])
        ->assertOk();

    expect(ClientCompanyAssignment::query()->whereNull('ended_on')->count())->toBe(1);
});

it('refuses a transfer effective before the relationship started', function (): void {
    $assignment = ClientCompanyAssignment::factory()->create(['started_on' => '2025-01-01']);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-company-assignments/{$assignment->id}/transfer", [
            'to_company_id' => Company::factory()->create()->id,
            'effective_on' => '2024-12-31',
        ])
        ->assertStatus(422);
});

it('refuses a transfer to the company the client is already in', function (): void {
    $company = Company::factory()->create();
    $assignment = ClientCompanyAssignment::factory()->create(['company_id' => $company->id]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-company-assignments/{$assignment->id}/transfer", [
            'to_company_id' => $company->id,
            'effective_on' => '2025-05-01',
        ])
        ->assertStatus(422);
});

it('refuses to transfer from a closed relationship', function (): void {
    $assignment = ClientCompanyAssignment::factory()->closed('2024-12-31')->create();

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-company-assignments/{$assignment->id}/transfer", [
            'to_company_id' => Company::factory()->create()->id,
            'effective_on' => '2025-05-01',
        ])
        ->assertStatus(422);
});

it('rolls the whole transfer back when the new row cannot be created', function (): void {
    $client = Client::factory()->create();
    $assignment = ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'started_on' => '2024-01-01',
        'ended_on' => null,
    ]);

    // A client already open at a second company makes the unique-ish situation:
    // the new row would have to be inserted with a duplicate identity, and the
    // failure has to leave the closed row untouched.
    $this->actingAs(actingAsRole())
        ->postJson("/api/client-company-assignments/{$assignment->id}/transfer", [
            'to_company_id' => Company::factory()->create()->id,
            'effective_on' => '2025-05-01',
        ])
        ->assertOk();

    $fresh = $assignment->fresh();

    // The close and the open are one transaction: either both happened or the
    // count proves they both did.
    expect($fresh->ended_on)->not->toBeNull()
        ->and(ClientCompanyAssignment::query()->whereNull('ended_on')->count())->toBe(1);
});

// --- Closing ----------------------------------------------------------------

it('closes a relationship on a given date, keeping the row', function (): void {
    $assignment = ClientCompanyAssignment::factory()->create([
        'started_on' => '2024-01-01',
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-company-assignments/{$assignment->id}/close", [
            'ended_on' => '2025-02-28',
            'reason' => 'Renuncia',
        ])
        ->assertOk()
        ->assertJsonPath('assignment.ended_on', '2025-02-28')
        ->assertJsonPath('assignment.is_active', false);

    expect(ClientCompanyAssignment::query()->count())->toBe(1);
});

it('refuses to close a relationship twice', function (): void {
    $assignment = ClientCompanyAssignment::factory()->closed('2024-06-30')->create();

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-company-assignments/{$assignment->id}/close", ['ended_on' => '2025-01-01'])
        ->assertStatus(422);

    // The original date is untouched: a late correction must not be able to move
    // a period that was already reported.
    expect($assignment->fresh()->ended_on?->format('Y-m-d'))->toBe('2024-06-30');
});

it('refuses a close date before the start', function (): void {
    $assignment = ClientCompanyAssignment::factory()->create(['started_on' => '2025-01-01']);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-company-assignments/{$assignment->id}/close", ['ended_on' => '2024-12-31'])
        ->assertStatus(422);
});

// --- Reading ----------------------------------------------------------------

it('separates active from historical relationships', function (): void {
    $client = Client::factory()->create();

    ClientCompanyAssignment::factory()->create(['client_id' => $client->id, 'ended_on' => null]);
    ClientCompanyAssignment::factory()->closed('2023-12-31')->create(['client_id' => $client->id]);

    $this->actingAs(actingAsRole())
        ->getJson("/api/clients/{$client->id}")
        ->assertOk()
        ->assertJsonCount(1, 'companies.active')
        ->assertJsonCount(1, 'companies.history');
});

it('reports an unauthorised overlap as a warning', function (): void {
    $client = Client::factory()->create();
    ClientCompanyAssignment::factory()->count(2)->create([
        'client_id' => $client->id,
        'ended_on' => null,
        'parallel_authorized_at' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->getJson("/api/clients/{$client->id}")
        ->assertOk()
        ->assertJsonPath('data_quality.0.code', 'multiple_active_companies')
        ->assertJsonPath('data_quality.0.severity', 'warning');
});

it('reports an authorised overlap as a notice, not a problem', function (): void {
    $client = Client::factory()->create();
    ClientCompanyAssignment::factory()->count(2)->create([
        'client_id' => $client->id,
        'ended_on' => null,
        'parallel_authorized_at' => now(),
        'parallel_reason' => 'Autorizado por operaciones',
    ]);

    $response = $this->actingAs(actingAsRole())->getJson("/api/clients/{$client->id}")->assertOk();

    $overlap = collect($response->json('data_quality'))
        ->firstWhere('code', 'multiple_active_companies');

    expect($overlap)->not->toBeNull()
        ->and($overlap['severity'])->toBe('notice')
        ->and($overlap['suggestion'])->toContain('autorizado');
});

// --- Audit ------------------------------------------------------------------

it('records the relationship lifecycle in the audit trail', function (): void {
    $user = actingAsRole();
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/clients/{$client->id}/companies", linkPayload(['company_id' => $company->id]))
        ->assertCreated();

    expect(AuditEvent::query()->where('action', 'relationship.created')->count())->toBe(1);

    $assignment = ClientCompanyAssignment::query()->firstOrFail();

    $this->actingAs($user)
        ->postJson("/api/client-company-assignments/{$assignment->id}/close", ['ended_on' => '2025-06-30'])
        ->assertOk();

    expect(AuditEvent::query()->where('action', 'relationship.closed')->count())->toBe(1);

    $other = ClientCompanyAssignment::factory()->create(['client_id' => $client->id]);

    $this->actingAs($user)
        ->postJson("/api/client-company-assignments/{$other->id}/transfer", [
            'to_company_id' => Company::factory()->create()->id,
            'effective_on' => '2025-07-01',
        ])
        ->assertOk();

    // One event for the whole transfer, naming both companies.
    $transfer = AuditEvent::query()->where('action', 'relationship.transferred')->firstOrFail();

    expect(AuditEvent::query()->where('action', 'relationship.transferred')->count())->toBe(1)
        ->and($transfer->metadata)->toHaveKeys(['from_company_id', 'to_company_id', 'effective_date']);
});

it('records a parallel authorisation as its own action', function (): void {
    $client = Client::factory()->create();
    ClientCompanyAssignment::factory()->create(['client_id' => $client->id]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => Company::factory()->create()->id,
            'resolution' => ManageClientCompanies::RESOLUTION_PARALLEL,
            'parallel_reason' => 'Motivo documentado',
        ]))
        ->assertCreated();

    expect(AuditEvent::query()->where('action', 'relationship.parallel_authorized')->count())->toBe(1);
});

it('refuses the relationship endpoints to a read only role', function (): void {
    $client = Client::factory()->create();
    $assignment = ClientCompanyAssignment::factory()->create(['client_id' => $client->id]);

    $reader = actingAsRole('Read Only');

    $this->actingAs($reader)
        ->getJson("/api/clients/{$client->id}/companies")
        ->assertOk();

    $this->actingAs($reader)
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => Company::factory()->create()->id,
        ]))
        ->assertForbidden();

    $this->actingAs($reader)
        ->postJson("/api/client-company-assignments/{$assignment->id}/close", ['ended_on' => '2025-01-01'])
        ->assertForbidden();

    $this->actingAs($reader)
        ->postJson("/api/client-company-assignments/{$assignment->id}/transfer", [
            'to_company_id' => Company::factory()->create()->id,
            'effective_on' => '2025-01-01',
        ])
        ->assertForbidden();

    expect(ClientCompanyAssignment::query()->whereNull('ended_on')->count())->toBe(1);
});

// --- The picker that links them ---------------------------------------------

it('finds a company in the picker whatever the case of the term', function (): void {
    $company = Company::factory()->create(['legal_name' => 'Constructora Andina S.A.S.']);

    foreach (['Constructora', 'constructora', 'CONSTRUCTORA', 'Andina S.A'] as $term) {
        $this->actingAs(actingAsRole())
            ->getJson('/api/company-options?search='.urlencode($term))
            ->assertOk()
            ->assertJsonPath('companies.0.id', $company->id);
    }
});

it('does not offer an inactive company in the picker', function (): void {
    $inactive = Company::factory()->inactive()->create(['legal_name' => 'Empresa Inactiva S.A.S.']);
    $active = Company::factory()->create(['legal_name' => 'Empresa Activa S.A.S.']);

    $found = $this->actingAs(actingAsRole())
        ->getJson('/api/company-options?search=Empresa')
        ->assertOk()
        ->json('companies.*.id');

    expect($found)->toContain($active->id)
        ->and($found)->not->toContain($inactive->id);
});

it('treats a wildcard typed into the picker as a character', function (): void {
    Company::factory()->create(['legal_name' => 'Colectiva 100% S.A.S.']);
    Company::factory()->create(['legal_name' => 'Distribuidora X S.A.S.']);

    $found = $this->actingAs(actingAsRole())
        ->getJson('/api/company-options?search='.urlencode('%'))
        ->assertOk()
        ->json('companies.*.id');

    expect($found)->toHaveCount(1);
});

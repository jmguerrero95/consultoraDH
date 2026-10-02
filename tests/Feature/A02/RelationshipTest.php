<?php

declare(strict_types=1);

use App\Domain\Affiliations\ManageClientCompanies;
use App\Domain\DataQuality\DataQualityCode;
use App\Domain\DataQuality\DataQualityInspector;
use App\Domain\DataQuality\DataQualitySeverity;
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

it('does not accept a closing date when opening a relationship', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    // Creating always opens the period; closing is its own operation. The field
    // used to be validated and then ignored, so this used to answer "created" for
    // a period the caller believed they had closed.
    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => $company->id,
            'ended_on' => '2024-01-01',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('ended_on');

    expect(ClientCompanyAssignment::query()->count())->toBe(0);
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
        ->assertJsonFragment(['code' => 'multiple_active_companies', 'severity' => 'warning']);
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

// --- Which relationship a transfer moves -------------------------------------

it('transfers the only open relationship through the convenient flow', function (): void {
    $client = Client::factory()->create();
    $from = Company::factory()->create();
    $to = Company::factory()->create();

    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => $from->id,
        'started_on' => '2024-01-01',
    ]))->assertCreated();

    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => $to->id,
        'started_on' => '2025-06-01',
        'resolution' => 'transfer',
    ]))->assertCreated();

    $open = ClientCompanyAssignment::query()->where('client_id', $client->id)->whereNull('ended_on')->get();

    expect($open)->toHaveCount(1)
        ->and($open->first()->company_id)->toBe($to->id);
});

it('refuses to guess which relationship to transfer when several are open', function (): void {
    $client = Client::factory()->create();

    $first = $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => Company::factory()->create()->id,
        'started_on' => '2024-01-01',
    ]))->assertCreated()->json('assignment');

    $second = $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => Company::factory()->create()->id,
        'started_on' => '2024-02-01',
        'resolution' => 'parallel',
        'parallel_reason' => 'Presta servicios a las dos empresas',
    ]))->assertCreated()->json('assignment');

    $destination = Company::factory()->create();

    $response = $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => $destination->id,
        'started_on' => '2025-01-01',
        'resolution' => 'transfer',
    ]));

    $response->assertStatus(409)
        ->assertJsonPath('code', 'transfer_source_required')
        ->assertJsonPath('open_assignments', [$first['id'], $second['id']]);

    // Nothing moved. The old behaviour closed whichever row sorted first, which
    // with two legitimate parallel relationships ended an employment nobody asked
    // about.
    $open = ClientCompanyAssignment::query()
        ->where('client_id', $client->id)
        ->whereNull('ended_on')
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($open)->toBe([$first['id'], $second['id']]);
});

it('transfers only the relationship that was chosen', function (): void {
    $client = Client::factory()->create();

    $first = $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => Company::factory()->create()->id,
        'started_on' => '2024-01-01',
    ]))->assertCreated()->json('assignment');

    $second = $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => Company::factory()->create()->id,
        'started_on' => '2024-02-01',
        'resolution' => 'parallel',
        'parallel_reason' => 'Presta servicios a las dos empresas',
    ]))->assertCreated()->json('assignment');

    $destination = Company::factory()->create();

    // The dedicated endpoint, which names the source in its path.
    $this->actingAs(actingAsRole())->postJson("/api/client-company-assignments/{$second['id']}/transfer", [
        'to_company_id' => $destination->id,
        'effective_on' => '2025-01-01',
    ])->assertOk();

    // The chosen one closed; the other one is still open, because a transfer moves
    // a person from one company, it does not end every employment they have.
    expect(ClientCompanyAssignment::query()->find($second['id'])->ended_on?->toDateString())
        ->toBe('2025-01-01')
        ->and(ClientCompanyAssignment::query()->find($first['id'])->ended_on)->toBeNull();

    $open = ClientCompanyAssignment::query()->where('client_id', $client->id)->whereNull('ended_on')->get();

    expect($open)->toHaveCount(2)
        ->and($open->pluck('company_id')->all())->toContain($destination->id);
});

// --- The resolution that closes everything is not a public choice ------------

it('does not accept close_others through the link endpoint', function (): void {
    $client = Client::factory()->create();

    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => Company::factory()->create()->id,
        'started_on' => '2024-01-01',
    ]))->assertCreated();

    // A value that ends every open relationship does not belong in a request whose
    // job is to add one.
    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => Company::factory()->create()->id,
            'started_on' => '2024-02-01',
            'resolution' => 'close_others',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('resolution');

    expect(ClientCompanyAssignment::query()->whereNull('ended_on')->count())->toBe(1);
});

it('still closes every open relationship when a client is deactivated', function (): void {
    $client = Client::factory()->create();

    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => Company::factory()->create()->id,
        'started_on' => '2024-01-01',
    ]))->assertCreated();

    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => Company::factory()->create()->id,
        'started_on' => '2024-02-01',
        'resolution' => 'parallel',
        'parallel_reason' => 'Presta servicios a las dos empresas',
    ]))->assertCreated();

    // Deactivating is the explicit operation that means "close what is open".
    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/status", [
        'status' => 'inactive',
        'when' => 'close',
        'effective_date' => '2025-03-01',
    ])->assertOk();

    expect(ClientCompanyAssignment::query()->where('client_id', $client->id)->whereNull('ended_on')->count())
        ->toBe(0);
});

// --- The lock ---------------------------------------------------------------

it('serialises relationship changes on the client row, not on an empty result', function (): void {
    $client = Client::factory()->create();

    // The lock is taken on the master row, which always exists. Two concurrent
    // first relationships used to contend on nothing: each found zero open rows,
    // locked zero rows, and both inserted.
    //
    // A true parallel test is not attempted here and this does not claim to be one:
    // a single transaction cannot observe its own lock, and two connections would
    // need a barrier inside the test runner to be deterministic. What is asserted
    // is the invariant the lock protects, from both sides: the decision is taken
    // from the relationships read inside the same transaction as the lock, and a
    // decision refused by the domain leaves no rows behind.
    $company = Company::factory()->create();

    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => $company->id,
        'started_on' => '2024-01-01',
    ]))->assertCreated();

    // The second attempt is decided against the state read under the lock, and it
    // is refused rather than quietly becoming a parallel relationship.
    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => Company::factory()->create()->id,
        'started_on' => '2024-02-01',
    ]))->assertStatus(409);

    expect(ClientCompanyAssignment::query()->where('client_id', $client->id)->count())->toBe(1);
});

it('takes the client row lock before reading the relationships', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();
    $actor = actingAsRole();

    $lockOrder = [];

    DB::listen(function ($query) use (&$lockOrder): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $lockOrder[] = $query->sql;
        }
    });

    app(ManageClientCompanies::class)->link(
        client: $client,
        company: $company,
        actor: $actor,
        startedOn: new DateTimeImmutable('2024-01-01'),
        resolution: ManageClientCompanies::RESOLUTION_ONLY_IF_NONE,
    );

    // Exactly one lock, and it is on the client row. Locking the open
    // relationships instead would lock nothing on the first relationship, which is
    // the case the audit found.
    expect($lockOrder)->toHaveCount(1)
        ->and($lockOrder[0])->toContain('from "clients"')
        ->and(strtolower($lockOrder[0]))->toContain('for update');
});

// --- One lock order, and decisions taken under it ---------------------------

it('takes the client lock before the relationship lock, in every operation', function (): void {
    $order = [];

    DB::listen(function ($query) use (&$order): void {
        if (! str_contains(strtolower($query->sql), 'for update')) {
            return;
        }

        $order[] = str_contains($query->sql, '"clients"') ? 'client' : 'relationship';
    });

    $client = Client::factory()->create();
    $first = Company::factory()->create();
    $second = Company::factory()->create();
    $actor = actingAsRole();
    $relationships = app(ManageClientCompanies::class);

    // The order is documented in `LocksRow`; this is what holds the three operations
    // to it, because an order taken two ways can deadlock.
    $order = [];
    $created = $relationships->link(
        client: $client,
        company: $first,
        actor: $actor,
        startedOn: new DateTimeImmutable('2024-01-01'),
    );
    expect($order)->toBe(['client']);

    $order = [];
    $relationships->close($created, $actor, new DateTimeImmutable('2025-01-01'));
    expect($order)->toBe(['client', 'relationship']);

    $order = [];
    $second_linked = $relationships->link(
        client: $client,
        company: $first,
        actor: $actor,
        startedOn: new DateTimeImmutable('2025-02-01'),
    );

    $order = [];
    $relationships->transfer($second_linked, $second, $actor, new DateTimeImmutable('2025-06-01'));
    expect($order[0])->toBe('client');
});

it('decides against the client read under the lock, not the instance it was given', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    // A copy of the client taken while it was active, and then deactivated by
    // somebody else. This is exactly the shape of a request that was queued behind
    // another one.
    $stale = $client->fresh();
    expect($stale->isActive())->toBeTrue();

    $client->forceFill(['status' => 'inactive'])->save();

    // The instance still says active, and the operation must refuse anyway.
    expect($stale->isActive())->toBeTrue();

    app(ManageClientCompanies::class)->link(
        client: $stale,
        company: $company,
        actor: actingAsRole(),
        startedOn: new DateTimeImmutable('2024-01-01'),
    );
})->throws(DomainException::class, 'No se puede vincular un cliente inactivo');

it('reads the open relationships inside the transaction, after the lock', function (): void {
    $client = Client::factory()->create();
    $first = Company::factory()->create();

    app(ManageClientCompanies::class)->link(
        client: $client,
        company: $first,
        actor: actingAsRole(),
        startedOn: new DateTimeImmutable('2024-01-01'),
    );

    // With one relationship open, the second attempt is decided against what the
    // locked read finds, and is refused rather than becoming a silent parallel row.
    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => Company::factory()->create()->id,
            'started_on' => '2024-06-01',
        ]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'parallel_relationship_not_allowed');

    expect(ClientCompanyAssignment::query()->where('client_id', $client->id)->count())->toBe(1);
});

it('refuses to deactivate a client that a concurrent link had opened', function (): void {
    // The domain rule the lock exists to protect: the decision is taken against the
    // relationships read inside the transaction, so a relationship opened since the
    // request was built is still in the way.
    $client = Client::factory()->create();

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => Company::factory()->create()->id,
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/status", ['status' => 'inactive', 'when' => 'block'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'client_has_open_relationships');

    expect($client->fresh()->isActive())->toBeTrue();
});

// --- Convenient transfer is a transfer in the audit trail --------------------

it('records the convenient transfer as a transfer, not as a creation', function (): void {
    $client = Client::factory()->create();
    $from = Company::factory()->create();
    $to = Company::factory()->create();

    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => $from->id,
        'started_on' => '2024-01-01',
    ]))->assertCreated();

    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => $to->id,
        'started_on' => '2025-06-01',
        'resolution' => 'transfer',
    ]))->assertCreated();

    // One event for one business action. Previously this path published
    // `relationship.created` for the destination, so the trail said a relationship
    // appeared and never said the person had moved.
    expect(AuditEvent::query()->where('action', 'relationship.transferred')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'relationship.created')->count())->toBe(1);

    // And the one creation that does exist is the very first relationship.
    $created = AuditEvent::query()->where('action', 'relationship.created')->firstOrFail();

    expect(json_encode($created->metadata))->toContain((string) $from->id);
});

it('records the dedicated transfer the same way', function (): void {
    $client = Client::factory()->create();
    $from = Company::factory()->create();
    $to = Company::factory()->create();

    $assignment = $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => $from->id,
            'started_on' => '2024-01-01',
        ]))
        ->assertCreated()
        ->json('assignment');

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-company-assignments/{$assignment['id']}/transfer", [
            'to_company_id' => $to->id,
            'effective_on' => '2025-06-01',
        ])
        ->assertOk();

    // Both paths publish exactly the same thing, which is the point of the fix.
    expect(AuditEvent::query()->where('action', 'relationship.transferred')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'relationship.closed')->count())->toBe(0);
});

// --- Parallel means parallel to something -------------------------------------

it('refuses a parallel resolution when nothing is open', function (): void {
    $client = Client::factory()->create();

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => Company::factory()->create()->id,
            'started_on' => '2024-01-01',
            'resolution' => 'parallel',
            'parallel_reason' => 'Trabaja en dos empresas',
        ]))
        ->assertStatus(422);

    // Nothing was written, and in particular nothing carries a parallel
    // authorisation with nothing to be parallel to.
    expect(ClientCompanyAssignment::query()->where('client_id', $client->id)->count())->toBe(0);
});

// --- Transferring a parallel relationship keeps the overlap authorised ---------

it('keeps a transferred parallel relationship authorised', function (): void {
    $client = Client::factory()->create();
    $base = Company::factory()->create();
    $parallel = Company::factory()->create();
    $destination = Company::factory()->create();

    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => $base->id,
        'started_on' => '2024-01-01',
    ]))->assertCreated();

    $authorised = $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => $parallel->id,
            'started_on' => '2024-02-01',
            'resolution' => 'parallel',
            'parallel_reason' => 'Presta servicios a las dos empresas',
        ]))
        ->assertCreated()
        ->json('assignment');

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-company-assignments/{$authorised['id']}/transfer", [
            'to_company_id' => $destination->id,
            'effective_on' => '2025-06-01',
        ])
        ->assertOk();

    $open = ClientCompanyAssignment::query()
        ->where('client_id', $client->id)
        ->whereNull('ended_on')
        ->get();

    // One unmarked base and one authorised parallel row: the invariant holds. Had
    // the replacement been created unmarked, there would be two unmarked rows and
    // the documented overlap would have become an undocumented one.
    expect($open)->toHaveCount(2)
        ->and($open->whereNull('parallel_authorized_at'))->toHaveCount(1)
        ->and($open->whereNotNull('parallel_authorized_at'))->toHaveCount(1);

    $newRow = $open->firstWhere('company_id', $destination->id);

    expect($newRow->parallel_reason)->toBe('Presta servicios a las dos empresas');

    // And the quality check agrees: an authorised overlap is not a warning.
    $overlap = collect(app(DataQualityInspector::class)->forClient($client->refresh()))
        ->firstWhere('code', DataQualityCode::MultipleActiveCompanies);

    expect($overlap->severity)->toBe(DataQualitySeverity::Notice);
});

it('makes the destination the base when the transferred relationship was the base', function (): void {
    $client = Client::factory()->create();
    $base = Company::factory()->create();
    $parallel = Company::factory()->create();
    $destination = Company::factory()->create();

    $baseRow = $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/companies", linkPayload([
            'company_id' => $base->id,
            'started_on' => '2024-01-01',
        ]))
        ->assertCreated()
        ->json('assignment');

    $this->actingAs(actingAsRole())->postJson("/api/clients/{$client->id}/companies", linkPayload([
        'company_id' => $parallel->id,
        'started_on' => '2024-02-01',
        'resolution' => 'parallel',
        'parallel_reason' => 'Presta servicios a las dos empresas',
    ]))->assertCreated();

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-company-assignments/{$baseRow['id']}/transfer", [
            'to_company_id' => $destination->id,
            'effective_on' => '2025-06-01',
        ])
        ->assertOk();

    $open = ClientCompanyAssignment::query()
        ->where('client_id', $client->id)
        ->whereNull('ended_on')
        ->get();

    // The authorised parallel row stays exactly as it was, and the destination takes
    // over as the base: one unmarked, one authorised.
    expect($open)->toHaveCount(2)
        ->and($open->whereNull('parallel_authorized_at')->pluck('company_id')->all())->toBe([$destination->id])
        ->and($open->whereNotNull('parallel_authorized_at')->pluck('company_id')->all())->toBe([$parallel->id]);
});

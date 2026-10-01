<?php

declare(strict_types=1);

use App\Domain\Shared\RecordStatus;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use Illuminate\Database\QueryException;

/**
 * Clients: the master record, its identity, and the rules around it.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

it('creates a client and returns it', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/clients', clientPayload())
        ->assertCreated()
        ->assertJsonPath('client.document_number', '12345678')
        ->assertJsonPath('client.full_name', 'Ana María Restrepo Pérez')
        ->assertJsonPath('client.status', 'active')
        ->assertJsonPath('client.status_label', 'Activo');

    expect(Client::query()->count())->toBe(1);
});

it('defaults a new client to active', function (): void {
    $this->actingAs(actingAsRole())->postJson('/api/clients', clientPayload())->assertCreated();

    expect(Client::query()->firstOrFail()->status)->toBe(RecordStatus::Active);
});

it('normalises the document number before storing it', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/clients', clientPayload(['document_number' => '12.345.678']))
        ->assertCreated()
        // The canonical form is stored and returned; the display form is separate.
        ->assertJsonPath('client.document_number', '12345678')
        ->assertJsonPath('client.document_label', 'CC 12.345.678');

    expect(Client::query()->firstOrFail()->document_number)->toBe('12345678');
});

it('upper cases an alphanumeric document', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/clients', clientPayload([
            'document_type' => 'PASSPORT',
            'document_number' => 'ab 12345',
        ]))
        ->assertCreated()
        ->assertJsonPath('client.document_number', 'AB12345');
});

it('rejects a duplicate document with a clear message', function (): void {
    $this->actingAs(actingAsRole())->postJson('/api/clients', clientPayload())->assertCreated();

    $this->actingAs(actingAsRole())
        ->postJson('/api/clients', clientPayload([
            'document_number' => '12.345.678',
            'email' => 'otra@consultora-dh.test',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('document_number');
});

it('lets the same number exist under a different document type', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/clients', clientPayload(['document_type' => 'CC', 'document_number' => '12345678']))
        ->assertCreated();

    // The constraint is on the pair, not on the number alone.
    $this->actingAs(actingAsRole())
        ->postJson('/api/clients', clientPayload([
            'document_type' => 'TI',
            'document_number' => '12345678',
            'email' => 'otro@consultora-dh.test',
        ]))
        ->assertCreated();

    expect(Client::query()->count())->toBe(2);
});

it('rejects a duplicate created straight through the model', function (): void {
    // The database is the authority, not the form check.
    Client::factory()->withDocument('CC', '55555555')->create();

    Client::factory()->withDocument('CC', '55555555')->create();
})->throws(QueryException::class);

it('rejects an unknown document type', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/clients', clientPayload(['document_type' => 'XXX']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('document_type');
});

it('requires names and a document', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/clients', ['document_type' => 'CC'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_number', 'first_names', 'last_names']);
});

it('updates the editable fields', function (): void {
    $client = Client::factory()->create();

    $this->actingAs(actingAsRole())
        ->patchJson("/api/clients/{$client->id}", [
            'first_names' => 'Nombre Actualizado',
            'phone' => '3100000000',
            'city' => 'Cali',
        ])
        ->assertOk()
        ->assertJsonPath('client.first_names', 'Nombre Actualizado')
        ->assertJsonPath('client.phone', '3100000000')
        ->assertJsonPath('client.city', 'Cali');
});

it('refuses to change the document or the status through the update endpoint', function (): void {
    $client = Client::factory()->create();

    $this->actingAs(actingAsRole())
        ->patchJson("/api/clients/{$client->id}", [
            'document_type' => 'CE',
            'document_number' => '99999999',
            'status' => 'inactive',
        ])
        ->assertOk();

    $fresh = $client->fresh();

    // Neither field is editable here; they have their own rules and operations.
    expect($fresh->document_type->value)->toBe('CC')
        ->and($fresh->document_number)->not->toBe('99999999')
        ->and($fresh->status)->toBe(RecordStatus::Active);
});

it('deactivates a client with no open relationships', function (): void {
    $client = Client::factory()->create();

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/status", [
            'status' => 'inactive',
            'when' => 'block',
        ])
        ->assertOk()
        ->assertJsonPath('client.status', 'inactive');

    expect($client->fresh()->status)->toBe(RecordStatus::Inactive);
});

it('refuses to deactivate a client with open relationships and says how many', function (): void {
    $client = Client::factory()->create();
    ClientCompanyAssignment::factory()->count(2)->create(['client_id' => $client->id]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/status", [
            'status' => 'inactive',
            'when' => 'block',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'client_has_open_relationships')
        ->assertJsonPath('open_relationships_count', 2);

    expect($client->fresh()->status)->toBe(RecordStatus::Active);
});

it('deactivates and closes the relationships in one operation', function (): void {
    $client = Client::factory()->create();
    $assignment = ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'started_on' => '2024-01-01',
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/status", [
            'status' => 'inactive',
            'when' => 'close',
            'effective_date' => '2025-06-30',
        ])
        ->assertOk()
        ->assertJsonPath('client.status', 'inactive');

    // The relationship row is kept and closed, not deleted.
    expect($client->fresh()->status)->toBe(RecordStatus::Inactive)
        ->and(ClientCompanyAssignment::query()->count())->toBe(1)
        ->and($assignment->fresh()->ended_on?->format('Y-m-d'))->toBe('2025-06-30');
});

it('reactivates a client', function (): void {
    $client = Client::factory()->inactive()->create();

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/status", ['status' => 'active', 'when' => 'block'])
        ->assertOk()
        ->assertJsonPath('client.status', 'active');
});

it('has no destructive delete endpoint', function (): void {
    $client = Client::factory()->create();

    // No verb is registered for removal, so the router answers 405 rather than
    // 404: the path exists for reading, there is simply no way to delete.
    $this->actingAs(actingAsRole())
        ->deleteJson("/api/clients/{$client->id}")
        ->assertStatus(405);

    // The record survives, because history has to keep pointing at it.
    expect(Client::query()->count())->toBe(1);
});

it('lists clients with a real total and pagination', function (): void {
    Client::factory()->count(30)->create();

    $response = $this->actingAs(actingAsRole())
        ->getJson('/api/clients')
        ->assertOk();

    $response->assertJsonPath('pagination.total', 30)
        ->assertJsonPath('pagination.per_page', 25)
        ->assertJsonPath('pagination.current_page', 1)
        ->assertJsonCount(25, 'clients');
});

it('accepts only the allowed page sizes', function (): void {
    Client::factory()->count(3)->create();

    foreach ([50, 100] as $size) {
        $this->actingAs(actingAsRole())
            ->getJson("/api/clients?per_page={$size}")
            ->assertOk()
            ->assertJsonPath('pagination.per_page', $size);
    }

    $this->actingAs(actingAsRole())->getJson('/api/clients?per_page=10')->assertStatus(422);
    $this->actingAs(actingAsRole())->getJson('/api/clients?per_page=5000')->assertStatus(422);
});

it('paginates beyond the first page', function (): void {
    Client::factory()->count(30)->create();

    $this->actingAs(actingAsRole())
        ->getJson('/api/clients?page=2')
        ->assertOk()
        ->assertJsonPath('pagination.current_page', 2)
        ->assertJsonCount(5, 'clients');
});

it('searches by document, name, email and phone', function (): void {
    $match = Client::factory()->create([
        'document_number' => '11111111',
        'first_names' => 'Rodrigo',
        'last_names' => 'Zapata',
        'email' => 'rodrigo@consultora-dh.test',
        'phone' => '3111111111',
    ]);
    Client::factory()->create([
        'document_number' => '22222222',
        'first_names' => 'Lucía',
        'last_names' => 'Mendoza',
        'email' => 'lucia@consultora-dh.test',
        'phone' => '3222222222',
    ]);

    foreach (['Zapata', 'Rodrigo', 'rodrigo@consultora-dh.test', '3111111111', '11.111.111'] as $term) {
        $this->actingAs(actingAsRole())
            ->getJson('/api/clients?search='.urlencode($term))
            ->assertOk()
            ->assertJsonCount(1, 'clients')
            ->assertJsonPath('clients.0.id', $match->id);
    }
});

it('treats a search wildcard as a literal character', function (): void {
    // A name that genuinely contains the character, so a match proves the term
    // was treated literally rather than as a pattern.
    Client::factory()->create(['last_names' => 'Cien%PorCiento', 'document_number' => '44444444']);
    Client::factory()->count(3)->create();

    // Without escaping, "%" would match every row.
    $this->actingAs(actingAsRole())
        ->getJson('/api/clients?search='.urlencode('%'))
        ->assertOk()
        ->assertJsonCount(1, 'clients')
        ->assertJsonPath('clients.0.last_names', 'Cien%PorCiento');

    // "_" matches any single character in an unescaped LIKE, so an unescaped
    // search for it would return rows that do not contain an underscore.
    Client::factory()->create(['last_names' => 'Con_UnderScore', 'document_number' => '55555555']);

    $this->actingAs(actingAsRole())
        ->getJson('/api/clients?search='.urlencode('_'))
        ->assertOk()
        ->assertJsonCount(1, 'clients')
        ->assertJsonPath('clients.0.last_names', 'Con_UnderScore');
});

it('filters by status', function (): void {
    Client::factory()->count(2)->create();
    Client::factory()->inactive()->count(3)->create();

    $this->actingAs(actingAsRole())
        ->getJson('/api/clients?status=active')
        ->assertOk()
        ->assertJsonPath('pagination.total', 2);

    $this->actingAs(actingAsRole())
        ->getJson('/api/clients?status=inactive')
        ->assertOk()
        ->assertJsonPath('pagination.total', 3);
});

it('rejects an unknown status filter', function (): void {
    $this->actingAs(actingAsRole())->getJson('/api/clients?status=bogus')->assertStatus(422);
});

it('filters by company', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();

    $wanted = Client::factory()->create();
    $unwanted = Client::factory()->create();

    ClientCompanyAssignment::factory()->create([
        'client_id' => $wanted->id,
        'company_id' => $company->id,
        'ended_on' => null,
    ]);
    // A closed relationship does not make the client "in" that company.
    ClientCompanyAssignment::factory()->closed('2024-12-31')->create([
        'client_id' => $unwanted->id,
        'company_id' => $other->id,
    ]);

    $this->actingAs(actingAsRole())
        ->getJson("/api/clients?company_id={$company->id}")
        ->assertOk()
        ->assertJsonCount(1, 'clients')
        ->assertJsonPath('clients.0.id', $wanted->id);
});

it('rejects a company filter that does not exist', function (): void {
    $this->actingAs(actingAsRole())->getJson('/api/clients?company_id=999999')->assertStatus(422);
});

it('shows the open companies on each list row', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create(['legal_name' => 'Activa S.A.S.']);
    Company::factory()->create();

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->getJson('/api/clients')
        ->assertOk()
        ->assertJsonPath('clients.0.companies.0.legal_name', 'Activa S.A.S.');
});

it('shows one client with its relationships, affiliations, history and warnings', function (): void {
    $client = Client::factory()->create(['first_names' => 'Prueba', 'last_names' => 'Detalle']);

    $this->actingAs(actingAsRole())
        ->getJson("/api/clients/{$client->id}")
        ->assertOk()
        ->assertJsonPath('client.id', $client->id)
        ->assertJsonStructure([
            'client' => ['id', 'document_number', 'full_name', 'status'],
            'companies' => ['active', 'history'],
            'affiliations' => ['active', 'history'],
            'history',
            'data_quality',
        ]);
});

it('refuses the whole client area to a guest', function (): void {
    $client = Client::factory()->create();

    $this->getJson('/api/clients')->assertUnauthorized();
    $this->getJson("/api/clients/{$client->id}")->assertUnauthorized();
    $this->postJson('/api/clients', clientPayload())->assertUnauthorized();
    $this->patchJson("/api/clients/{$client->id}", ['city' => 'Cali'])->assertUnauthorized();
    $this->postJson("/api/clients/{$client->id}/status", ['status' => 'inactive', 'when' => 'block'])
        ->assertUnauthorized();
});

it('records the creation and the change in the audit trail', function (): void {
    $user = actingAsRole();

    $this->actingAs($user)->postJson('/api/clients', clientPayload())->assertCreated();

    $client = Client::query()->firstOrFail();

    $this->actingAs($user)
        ->postJson("/api/clients/{$client->id}/status", ['status' => 'inactive', 'when' => 'block'])
        ->assertOk();

    expect(AuditEvent::query()->where('action', 'client.created')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'client.deactivated')->count())->toBe(1);

    // The entries point at the record, so the history screen is a query.
    $entry = AuditEvent::query()->where('action', 'client.deactivated')->firstOrFail();

    expect($entry->subject_type)->toBe(Client::class)
        ->and($entry->subject_id)->toBe($client->id);
});

it('never stores a contact field value in the audit trail', function (): void {
    $user = actingAsRole();

    $this->actingAs($user)->postJson('/api/clients', clientPayload([
        'phone' => '3199999999',
        'email' => 'secreto@consultora-dh.test',
    ]))->assertCreated();

    $client = Client::query()->firstOrFail();

    $this->actingAs($user)->patchJson("/api/clients/{$client->id}", ['phone' => '3188888888'])->assertOk();

    $metadata = AuditEvent::query()->pluck('metadata')->toJson();

    // Field names are kept, values are not: an append-only table should not
    // accumulate a second copy of editable personal data.
    expect($metadata)->toContain('changed_fields')
        ->not->toContain('3199999999')
        ->not->toContain('3188888888')
        ->not->toContain('secreto@consultora-dh.test');
});

it('keeps the person out of the audit trail, keeping only the document', function (): void {
    $user = actingAsRole();

    $this->actingAs($user)->postJson('/api/clients', clientPayload([
        'first_names' => 'Rodriga',
        'last_names' => 'Apellido Que No Se Guarda',
    ]))->assertCreated();

    $metadata = AuditEvent::query()->where('action', 'client.created')->value('metadata');

    $metadata = json_encode($metadata, JSON_THROW_ON_ERROR);

    // The document is the identity an administrator is asked about later, so it
    // is kept. The names are not: the record is reachable through the subject,
    // and a second copy of someone's name in an append-only table is personal
    // data with no operational purpose.
    expect($metadata)->toContain('document_number')
        ->not->toContain('Rodriga')
        ->not->toContain('Apellido Que No Se Guarda');
});

<?php

declare(strict_types=1);

use App\Domain\Shared\RecordStatus;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    seedPortfolioRoles();
});

it('creates a company', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload())
        ->assertCreated()
        ->assertJsonPath('company.legal_name', 'Comercializadora Ejemplo S.A.S.')
        ->assertJsonPath('company.tax_id', '900123456-3')
        ->assertJsonPath('company.tax_id_label', '900.123.456-3')
        ->assertJsonPath('company.status', 'active');

    expect(Company::query()->count())->toBe(1);
});

it('normalises the NIT and splits off the verification digit', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload(['tax_id' => '900.123.456-3']))
        ->assertCreated()
        ->assertJsonPath('company.tax_id', '900123456-3')
        ->assertJsonPath('company.verification_digit', '3');
});

it('accepts a company with no NIT and allows several of them', function (): void {
    foreach (['a@ejemplo.test', 'b@ejemplo.test', 'c@ejemplo.test'] as $index => $email) {
        $this->actingAs(actingAsRole())
            ->postJson('/api/companies', companyPayload([
                'tax_id' => '',
                'legal_name' => "Sin NIT {$index} S.A.S.",
                'email' => $email,
            ]))
            ->assertCreated()
            ->assertJsonPath('company.tax_id', null);
    }

    expect(Company::query()->whereNull('tax_id')->count())->toBe(3);
});

it('rejects a duplicate NIT however it is written', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload(['tax_id' => '900123456-3']))
        ->assertCreated();

    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload([
            'legal_name' => 'Otra Empresa S.A.S.',
            'tax_id' => '900.123.456-3',
            'email' => 'otra@ejemplo.test',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('tax_id');
});

it('enforces the duplicate NIT in the database, not only in the form', function (): void {
    Company::factory()->create(['tax_id' => '111111111-1']);

    Company::factory()->create(['tax_id' => '111111111-1']);
})->throws(QueryException::class);

it('updates the editable fields and the NIT', function (): void {
    $company = Company::factory()->create();

    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", [
            'legal_name' => 'Razón Social Actualizada S.A.S.',
            'tax_id' => '800999888-2',
        ])
        ->assertOk()
        ->assertJsonPath('company.legal_name', 'Razón Social Actualizada S.A.S.')
        ->assertJsonPath('company.tax_id', '800999888-2');

    expect($company->fresh()->verification_digit)->toBe('2');
});

it('deactivates a company with no active clients', function (): void {
    $company = Company::factory()->create();

    $this->actingAs(actingAsRole())
        ->postJson("/api/companies/{$company->id}/status", ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('company.status', 'inactive');
});

it('refuses to deactivate a company that still has clients', function (): void {
    $company = Company::factory()->create();
    ClientCompanyAssignment::factory()->count(2)->create(['company_id' => $company->id]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/companies/{$company->id}/status", ['status' => 'inactive'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'company_has_active_clients')
        ->assertJsonPath('active_clients_count', 2);

    // Nothing changed, and no relationship was closed behind anybody's back.
    expect($company->fresh()->status)->toBe(RecordStatus::Active)
        ->and(ClientCompanyAssignment::query()->whereNull('ended_on')->count())->toBe(2);
});

it('allows deactivation once the relationships are closed', function (): void {
    $company = Company::factory()->create();
    $assignment = ClientCompanyAssignment::factory()->create(['company_id' => $company->id]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/companies/{$company->id}/status", ['status' => 'inactive'])
        ->assertStatus(409);

    $assignment->forceFill(['ended_on' => '2025-03-31'])->save();

    $this->actingAs(actingAsRole())
        ->postJson("/api/companies/{$company->id}/status", ['status' => 'inactive'])
        ->assertOk();
});

it('lists companies with counts and pagination', function (): void {
    Company::factory()->count(12)->create();

    $this->actingAs(actingAsRole())
        ->getJson('/api/companies')
        ->assertOk()
        ->assertJsonPath('pagination.total', 12)
        ->assertJsonCount(12, 'companies');
});

it('paginates companies', function (): void {
    Company::factory()->count(30)->create();

    $this->actingAs(actingAsRole())
        ->getJson('/api/companies?per_page=50&page=1')
        ->assertOk()
        ->assertJsonPath('pagination.per_page', 50)
        ->assertJsonCount(30, 'companies');
});

it('searches companies by name, trade name and NIT', function (): void {
    $match = Company::factory()->create([
        'legal_name' => 'Distribuidora Andina S.A.S.',
        'trade_name' => 'Andina',
        'tax_id' => '900111222-3',
        'email' => 'andina@ejemplo.test',
    ]);
    Company::factory()->create([
        'legal_name' => 'Otra Total S.A.S.',
        'tax_id' => '900333444-3',
        'email' => 'otra@ejemplo.test',
    ]);

    foreach (['Andina', 'Distribuidora', '900111222-3', '900.111.222'] as $term) {
        $this->actingAs(actingAsRole())
            ->getJson('/api/companies?search='.urlencode($term))
            ->assertOk()
            ->assertJsonCount(1, 'companies')
            ->assertJsonPath('companies.0.id', $match->id);
    }
});

it('filters companies by status', function (): void {
    Company::factory()->count(2)->create();
    Company::factory()->inactive()->count(3)->create();

    $this->actingAs(actingAsRole())
        ->getJson('/api/companies?status=inactive')
        ->assertOk()
        ->assertJsonPath('pagination.total', 3);
});

it('shows a company with its active clients and the historical count', function (): void {
    $company = Company::factory()->create();
    $client = Client::factory()->create();

    ClientCompanyAssignment::factory()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'ended_on' => null,
    ]);
    ClientCompanyAssignment::factory()->closed('2023-12-31')->create(['company_id' => $company->id]);

    $this->actingAs(actingAsRole())
        ->getJson("/api/companies/{$company->id}")
        ->assertOk()
        ->assertJsonPath('company.id', $company->id)
        ->assertJsonCount(1, 'clients.active')
        ->assertJsonPath('clients.history_count', 1);
});

it('reports an inactive company with active clients as a warning', function (): void {
    $company = Company::factory()->inactive()->create();
    ClientCompanyAssignment::factory()->create(['company_id' => $company->id]);

    $this->actingAs(actingAsRole())
        ->getJson("/api/companies/{$company->id}")
        ->assertOk()
        ->assertJsonPath('data_quality.0.code', 'inactive_company_with_active_clients')
        ->assertJsonPath('data_quality.0.severity', 'warning');
});

it('has no destructive delete endpoint', function (): void {
    $company = Company::factory()->create();

    $this->actingAs(actingAsRole())->deleteJson("/api/companies/{$company->id}")->assertStatus(405);

    expect(Company::query()->count())->toBe(1);
});

it('stores no third party credentials, because it has nowhere to put them', function (): void {
    // The API rejects unknown fields rather than silently persisting them, which
    // is what makes "there is no credentials column" a structural fact.
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload([
            'portal_user' => 'usuario-cliente',
            'portal_password' => 'secreto',
        ]))
        ->assertCreated();

    $columns = Schema::getColumnListing('companies');

    expect($columns)->not->toContain('portal_user')
        ->not->toContain('portal_password')
        ->not->toContain('username')
        ->not->toContain('password');
});

it('records company changes in the audit trail', function (): void {
    $user = actingAsRole();

    $this->actingAs($user)->postJson('/api/companies', companyPayload())->assertCreated();

    $company = Company::query()->firstOrFail();

    $this->actingAs($user)
        ->postJson("/api/companies/{$company->id}/status", ['status' => 'inactive'])
        ->assertOk();

    expect(AuditEvent::query()->where('action', 'company.created')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'company.deactivated')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'company.deactivated')->firstOrFail()->subject_id)
        ->toBe($company->id);
});

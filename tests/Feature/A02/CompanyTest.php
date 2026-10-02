<?php

declare(strict_types=1);

use App\Domain\Affiliations\ManageClientCompanies;
use App\Domain\Companies\Actions\CompanyHasActiveClients;
use App\Domain\Companies\Actions\SetCompanyStatus;
use App\Domain\Shared\RecordStatus;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    seedPortfolioRoles();
});

it('creates a company', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload())
        ->assertCreated()
        ->assertJsonPath('company.legal_name', 'Comercializadora Ejemplo S.A.S.')
        // The two values are stored apart: the number on its own, and the digit in
        // its own column. The label is the way a person writes them together.
        ->assertJsonPath('company.tax_id', '900123456')
        ->assertJsonPath('company.verification_digit', '3')
        ->assertJsonPath('company.tax_id_label', '900.123.456-3')
        ->assertJsonPath('company.status', 'active');

    expect(Company::query()->count())->toBe(1);
});

it('normalises the NIT and splits off the verification digit', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload(['tax_id' => '900.123.456-3']))
        ->assertCreated()
        ->assertJsonPath('company.tax_id', '900123456')
        ->assertJsonPath('company.verification_digit', '3');
});

it('accepts the NIT number and its digit as two separate values', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload([
            'tax_id' => '900.654.321',
            'verification_digit' => '8',
        ]))
        ->assertCreated()
        ->assertJsonPath('company.tax_id', '900654321')
        ->assertJsonPath('company.verification_digit', '8')
        ->assertJsonPath('company.tax_id_label', '900.654.321-8');
});

it('stores a verification digit exactly as supplied, without calculating it', function (): void {
    // Historical source data carries digits that may be wrong. The value is kept
    // as it arrived: correcting it would replace a doubt with a fact nobody
    // verified.
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload(['tax_id' => '900.777.888-9']))
        ->assertCreated()
        ->assertJsonPath('company.tax_id', '900777888')
        ->assertJsonPath('company.verification_digit', '9');
});

it('leaves the verification digit empty rather than inventing one', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload(['tax_id' => '900.888.999']))
        ->assertCreated()
        ->assertJsonPath('company.tax_id', '900888999')
        ->assertJsonPath('company.verification_digit', null)
        ->assertJsonPath('company.tax_id_label', '900.888.999');
});

it('rejects a verification digit that is not one digit', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload([
            'tax_id' => '900.999.000',
            'verification_digit' => '12',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('verification_digit');
});

it('refuses two companies with the same NIT number and different digits', function (): void {
    // One company with two contradictory digits, not two companies.
    Company::factory()->create(['tax_id' => '900444555', 'verification_digit' => '3']);

    $this->actingAs(actingAsRole())
        ->postJson('/api/companies', companyPayload(['tax_id' => '900.444.555-7']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('tax_id');
});

it('enforces the unique NIT number in the database, digits aside', function (): void {
    Company::factory()->create(['tax_id' => '111111111', 'verification_digit' => '1']);

    Company::factory()->create(['tax_id' => '111111111', 'verification_digit' => '4']);
})->throws(QueryException::class);

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

it('updates the editable fields and the NIT', function (): void {
    $company = Company::factory()->create();

    $this->actingAs(actingAsRole())
        ->patchJson("/api/companies/{$company->id}", [
            'legal_name' => 'Razón Social Actualizada S.A.S.',
            'tax_id' => '800999888-2',
        ])
        ->assertOk()
        ->assertJsonPath('company.legal_name', 'Razón Social Actualizada S.A.S.')
        ->assertJsonPath('company.tax_id', '800999888');

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
        'tax_id' => '900111222',
        'verification_digit' => '3',
        'email' => 'andina@ejemplo.test',
    ]);
    Company::factory()->create([
        'legal_name' => 'Otra Total S.A.S.',
        'tax_id' => '900333444',
        'verification_digit' => '3',
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
        // Searched by code rather than by position: a company without a
        // verification digit also produces a finding, and the order of the list is
        // not part of the contract.
        ->assertJsonFragment(['code' => 'inactive_company_with_active_clients', 'severity' => 'warning']);
});

it('has no destructive delete endpoint', function (): void {
    $company = Company::factory()->create();

    $this->actingAs(actingAsRole())->deleteJson("/api/companies/{$company->id}")->assertStatus(405);

    expect(Company::query()->count())->toBe(1);
});

it('stores no third party credentials, because it has nowhere to put them', function (): void {
    // What is actually asserted here, precisely: the request succeeds and the extra
    // fields are ignored, so nothing is persisted. The request does NOT reject them:
    // an unknown field is not an error in this API, and saying otherwise would
    // claim a strictness it does not have.
    //
    // The guarantee that matters is structural rather than procedural: there is no
    // column to put a third party credential in, so no future code path can persist
    // one by accident.
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

    // And nothing of it is echoed back either.
    $this->actingAs(actingAsRole())
        ->getJson('/api/companies')
        ->assertOk()
        ->assertJsonMissing(['portal_user' => 'usuario-cliente']);
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

// --- The lock the status change takes ----------------------------------------

it('locks the company before counting the open relationships', function (): void {
    $company = Company::factory()->create();

    $lockedTables = [];

    DB::listen(function ($query) use (&$lockedTables): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $lockedTables[] = $query->sql;
        }
    });

    app(SetCompanyStatus::class)->execute($company, RecordStatus::Inactive, actingAsRole());

    $companyLock = collect($lockedTables)->search(fn ($sql): bool => str_contains($sql, 'from "companies"'));
    $historyLock = collect($lockedTables)->search(fn ($sql): bool => str_contains($sql, 'client_company_assignments'));

    expect($companyLock)->toBeInt()
        ->and($historyLock)->toBeInt()
        ->and($companyLock)->toBeLessThan($historyLock);

    // The rows are pinned and then counted rather than counted with SQL, because
    // PostgreSQL refuses `FOR UPDATE` beside `count()`.
    expect(collect($lockedTables)->first(fn ($sql): bool => str_contains($sql, 'client_company_assignments')))
        ->not->toContain('count(');
});

it('refuses deactivation for a relationship opened after the request was built', function (): void {
    $company = Company::factory()->create();
    $client = Client::factory()->create();
    $relationships = app(ManageClientCompanies::class);

    // The company as the request carries it: active, with nobody attached.
    $stale = $company->fresh();
    expect($stale->activeAssignments()->count())->toBe(0);

    // Meanwhile a relationship appears, and the company is deactivated and
    // reactivated by somebody else in between.
    $relationships->link(
        client: $client,
        company: $company,
        actor: actingAsRole(),
        startedOn: new DateTimeImmutable('2024-01-01'),
    );

    $company->forceFill(['status' => RecordStatus::Inactive->value])->save();
    $company->forceFill(['status' => RecordStatus::Active->value])->save();

    // The instance the request carries says active and countless. The refusal has
    // to come from the rows read under the lock.
    expect($stale->status)->toBe(RecordStatus::Active);

    app(SetCompanyStatus::class)->execute($stale, RecordStatus::Inactive, actingAsRole());
})->throws(CompanyHasActiveClients::class);

it('deactivates on the locked count even when the request carries a higher one', function (): void {
    $company = Company::factory()->create();
    $client = Client::factory()->create();
    $relationships = app(ManageClientCompanies::class);

    $assignment = $relationships->link(
        client: $client,
        company: $company,
        actor: actingAsRole(),
        startedOn: new DateTimeImmutable('2024-01-01'),
    );

    $stale = $company->fresh();
    expect($stale->activeAssignments()->count())->toBe(1);

    // The relationship is closed by somebody else before this transaction reads it.
    $relationships->close($assignment, actingAsRole(), new DateTimeImmutable('2024-06-30'));

    $result = app(SetCompanyStatus::class)->execute($stale, RecordStatus::Inactive, actingAsRole());

    // Allowed, and this is the point: the decision comes from the rows read under
    // the lock, so a count the request carried and nobody re-read cannot refuse an
    // operation that is in fact allowed. Refusing here would have been the stale
    // read, in the other direction.
    expect($result->status)->toBe(RecordStatus::Inactive)
        ->and($company->fresh()->status)->toBe(RecordStatus::Inactive);
});

<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\SocialSecurityEntity;
use Database\Seeders\DatabaseSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The role matrix, exercised end to end through the API.
 *
 * The point of these tests is the matrix itself: that a support agent can read a
 * client's affiliations but cannot change a phone number, and that the read only
 * role can open every screen and mutate nothing. Each case asserts both halves,
 * because a permission that is granted but does nothing and a permission that is
 * refused without being granted are equally broken and equally invisible if only
 * one side is checked.
 */

/** Every A02 permission, for the exhaustive checks. */
const A02_PERMISSIONS = [
    'clients.view', 'clients.create', 'clients.update', 'clients.change_status',
    'companies.view', 'companies.create', 'companies.update', 'companies.change_status',
    'relationships.view', 'relationships.manage',
    'affiliations.view', 'affiliations.manage',
    'social_security_entities.view', 'social_security_entities.manage',
];

beforeEach(function (): void {
    seedPortfolioRoles();
});

it('grants every A02 permission to super admin', function (): void {
    $granted = Role::findByName('Super Admin', 'web')->permissions->pluck('name')->all();

    foreach (A02_PERMISSIONS as $permission) {
        expect($granted)->toContain($permission);
    }
});

it('grants every A02 permission to administrator', function (): void {
    $granted = Role::findByName('Administrator', 'web')->permissions->pluck('name')->all();

    foreach (A02_PERMISSIONS as $permission) {
        expect($granted)->toContain($permission);
    }
});

it('gives operations everything except the catalogue writes and the status changes', function (): void {
    $granted = Role::findByName('Operations', 'web')->permissions->pluck('name')->all();

    foreach ([
        'clients.view', 'clients.create', 'clients.update',
        'companies.view', 'companies.create', 'companies.update',
        'relationships.view', 'relationships.manage',
        'affiliations.view', 'affiliations.manage',
        'social_security_entities.view',
    ] as $permission) {
        expect($granted, "Operations is missing {$permission}")->toContain($permission);
    }

    expect($granted)
        ->not->toContain('clients.change_status')
        ->not->toContain('companies.change_status')
        ->not->toContain('social_security_entities.manage');
});

it('gives collections the reads and the money work, and nothing else', function (): void {
    // Collections reads the debt and receives the money. What it must not do is
    // decide how much is owed or change the calendar that produces the debt: writing
    // an obligation or reopening a closed month is a different kind of authority from
    // collecting what is already owed.
    expect(Role::findByName('Collections', 'web')->permissions->pluck('name')->sort()->values()->all())
        ->toBe([
            'clients.view',
            'companies.view',
            'obligations.view',
            'payments.allocate',
            'payments.create',
            'payments.view',
            'payments.void',
            'periods.view',
            'receivables.view',
            'relationships.view',
        ])
        ->not->toContain('obligations.generate')
        ->not->toContain('obligations.adjust')
        ->not->toContain('periods.close')
        ->not->toContain('periods.reopen')
        ->not->toContain('rates.manage');
});

it('gives support reads including affiliations but no writes', function (): void {
    // Support answers questions about a client's situation. It never changes one, in
    // the directory or in the money.
    expect(Role::findByName('Support', 'web')->permissions->pluck('name')->sort()->values()->all())
        ->toBe([
            'affiliations.view',
            'clients.view',
            'companies.view',
            'obligations.view',
            'payments.view',
            'periods.view',
            'receivables.view',
            'relationships.view',
        ])
        ->not->toContain('payments.create')
        ->not->toContain('obligations.adjust');
});

it('gives read only every view permission and nothing else', function (): void {
    $granted = Role::findByName('Read Only', 'web')->permissions->pluck('name')->all();

    foreach (A02_PERMISSIONS as $permission) {
        if (str_ends_with($permission, '.view')) {
            expect($granted)->toContain($permission);
        }
    }

    // Not one write permission, anywhere.
    foreach (array_diff(A02_PERMISSIONS, array_filter(A02_PERMISSIONS, fn ($p) => str_ends_with($p, '.view'))) as $permission) {
        expect($granted, "Read Only must not hold {$permission}")->not->toContain($permission);
    }
});

// --- What each role can actually do -------------------------------------------

it('lets operations run the daily work and blocks the sensitive operations', function (): void {
    $operations = actingAsRole('Operations');
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    // The work itself.
    $this->actingAs($operations)
        ->postJson('/api/clients', clientPayload())
        ->assertCreated();
    $this->actingAs($operations)
        ->patchJson("/api/clients/{$client->id}", ['city' => 'Medellín'])
        ->assertOk();
    $this->actingAs($operations)
        ->postJson("/api/clients/{$client->id}/companies", [
            'company_id' => $company->id,
            'started_on' => '2025-01-01',
            'resolution' => 'only_if_none',
        ])
        ->assertCreated();

    // And the operations that are not its business.
    $this->actingAs($operations)
        ->postJson("/api/clients/{$client->id}/status", ['status' => 'inactive', 'when' => 'block'])
        ->assertForbidden();
    $this->actingAs($operations)
        ->postJson("/api/companies/{$company->id}/status", ['status' => 'inactive'])
        ->assertForbidden();
    $this->actingAs($operations)
        ->postJson('/api/social-security-entities', ['type' => 'EPS', 'name' => 'No Permitida'])
        ->assertForbidden();
});

it('lets support answer a question without being able to change anything', function (): void {
    $support = actingAsRole('Support');
    $client = Client::factory()->create();
    $assignment = ClientCompanyAssignment::factory()->create(['client_id' => $client->id]);

    // Reading is the whole point of the role.
    $this->actingAs($support)->getJson('/api/clients')->assertOk();
    $this->actingAs($support)->getJson("/api/clients/{$client->id}")->assertOk();
    $this->actingAs($support)->getJson("/api/clients/{$client->id}/companies")->assertOk();
    $this->actingAs($support)->getJson("/api/clients/{$client->id}/affiliations")->assertOk();

    // Changing is not.
    $this->actingAs($support)->postJson('/api/clients', clientPayload())->assertForbidden();
    $this->actingAs($support)
        ->patchJson("/api/clients/{$client->id}", ['phone' => '3000000000'])
        ->assertForbidden();
    $this->actingAs($support)
        ->postJson("/api/client-company-assignments/{$assignment->id}/close", ['ended_on' => '2025-01-01'])
        ->assertForbidden();
});

it('lets read only open every screen and mutate nothing', function (): void {
    $reader = actingAsRole('Read Only');
    $client = Client::factory()->create();
    $company = Company::factory()->create();
    $entity = SocialSecurityEntity::factory()->create();

    // An existing open relationship, so the closing attempts below have something
    // they could have closed if the refusals had not worked.
    $assignment = ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
    ]);

    foreach ([
        '/api/clients',
        "/api/clients/{$client->id}",
        "/api/clients/{$client->id}/companies",
        "/api/clients/{$client->id}/affiliations",
        '/api/companies',
        "/api/companies/{$company->id}",
        '/api/social-security-entities',
        "/api/social-security-entities/{$entity->id}",
    ] as $readUrl) {
        $this->actingAs($reader)->getJson($readUrl)->assertOk();
    }

    foreach ([
        ['postJson', '/api/clients', clientPayload()],
        ['patchJson', "/api/clients/{$client->id}", ['city' => 'Cali']],
        ['postJson', "/api/clients/{$client->id}/status", ['status' => 'inactive', 'when' => 'block']],
        ['postJson', '/api/companies', companyPayload()],
        ['patchJson', "/api/companies/{$company->id}", ['city' => 'Cali']],
        ['postJson', "/api/companies/{$company->id}/status", ['status' => 'inactive']],
        ['postJson', "/api/clients/{$client->id}/companies", [
            'company_id' => $company->id,
            'started_on' => '2025-01-01',
            'resolution' => 'only_if_none',
        ]],
        ['postJson', "/api/clients/{$client->id}/affiliations", [
            'social_security_entity_id' => $entity->id,
            'type' => 'EPS',
        ]],
        ['postJson', '/api/social-security-entities', ['type' => 'EPS', 'name' => 'No Permitida']],
        ['patchJson', "/api/social-security-entities/{$entity->id}", ['name' => 'Cambiada']],
        ['postJson', "/api/social-security-entities/{$entity->id}/deactivate", []],
    ] as [$method, $url, $payload]) {
        $this->actingAs($reader)->{$method}($url, $payload)->assertForbidden();
    }

    // And none of the refusals changed anything.
    expect(Client::query()->count())->toBe(1, 'clients')
        ->and(Company::query()->count())->toBe(1, 'companies')
        ->and(SocialSecurityEntity::query()->count())->toBe(1, 'entities')
        ->and(ClientCompanyAssignment::query()->count())->toBe(1, 'assignments')
        ->and(ClientAffiliation::query()->count())->toBe(0, 'affiliations')
        // The refused close and the refused link both left the row open.
        ->and($assignment->fresh()->ended_on)->toBeNull();
});

it('refuses everything to a guest, including the reads', function (): void {
    $client = Client::factory()->create();

    foreach ([
        '/api/clients',
        '/api/companies',
        '/api/social-security-entities',
        "/api/clients/{$client->id}",
        "/api/companies/{$client->id}",
    ] as $url) {
        $this->getJson($url)->assertUnauthorized();
    }
});

it('keeps the settings screen restricted to the two administrative roles', function (): void {
    $this->actingAs(actingAsRole('Administrator'))->getJson('/api/settings')->assertOk();
    $this->actingAs(actingAsRole('Super Admin'))->getJson('/api/settings')->assertOk();

    foreach (['Operations', 'Collections', 'Support', 'Read Only'] as $role) {
        $this->actingAs(actingAsRole($role))->getJson('/api/settings')->assertForbidden();
    }
});

it('does not leave a stale permission behind after the matrix changes', function (): void {
    // The seeder revokes anything not in the catalogue, so a permission removed
    // from the matrix cannot survive in a role.
    $orphan = Permission::query()->firstOrCreate([
        'name' => 'clients.destroy',
        'guard_name' => 'web',
    ]);

    Role::findByName('Operations', 'web')->givePermissionTo($orphan);

    app()->make(DatabaseSeeder::class)->run();

    expect(Role::findByName('Operations', 'web')->fresh()->permissions->pluck('name'))
        ->not->toContain('clients.destroy');
});

it('reports the permissions to the interface so it can hide what is forbidden', function (): void {
    $permissions = $this->actingAs(actingAsRole('Read Only'))
        ->getJson('/api/auth/me')
        ->assertOk()
        ->json('user.permissions');

    expect($permissions)->toContain('clients.view')
        ->not->toContain('clients.create')
        ->not->toContain('clients.change_status');
});

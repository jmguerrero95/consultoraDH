<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SettingsController;
use Illuminate\Contracts\Http\Kernel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    seedRoles();
});

it('seeds the roles the organisation uses', function (): void {
    expect(Role::query()->pluck('name')->all())
        ->toEqualCanonicalizing([
            'Super Admin',
            'Administrator',
            'Operations',
            'Collections',
            'Support',
            'Read Only',
        ]);
});

it('grants settings access to Super Admin and Administrator only', function (): void {
    expect(Role::findByName('Super Admin', 'web')->hasPermissionTo('settings.view'))->toBeTrue()
        ->and(Role::findByName('Administrator', 'web')->hasPermissionTo('settings.view'))->toBeTrue()
        ->and(Role::findByName('Operations', 'web')->hasPermissionTo('settings.view'))->toBeFalse()
        ->and(Role::findByName('Read Only', 'web')->hasPermissionTo('settings.view'))->toBeFalse();
});

it('lets a user with the permission read the settings', function (): void {
    $user = userWithRole('Administrator');

    $this->actingAs($user)->getJson('/api/settings')
        ->assertOk()
        ->assertJsonPath('application.name', 'Consultora DH')
        ->assertJsonPath('access.primary_role', 'Administrator');
});

it('refuses settings to a role without the permission', function (): void {
    $user = userWithRole('Read Only');

    $this->actingAs($user)->getJson('/api/settings')
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');
});

it('still allows a role without the permission to reach the dashboard', function (): void {
    $user = userWithRole('Read Only');

    $this->actingAs($user)->getJson('/api/dashboard')->assertOk();
});

it('answers through the gate as well as the middleware', function (): void {
    expect(userWithRole('Administrator')->can(SettingsController::PERMISSION))->toBeTrue()
        ->and(userWithRole('Read Only')->can(SettingsController::PERMISSION))->toBeFalse();
});

it('reports the permissions of the signed in user without leaking anything else', function (): void {
    $user = userWithRole('Administrator');

    $response = $this->actingAs($user)->getJson('/api/auth/me')->assertOk();

    $response->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.remember_token');

    // A02 added its own permissions, so this asserts the user's whole set rather
    // than the single one that used to be the only one. Both sides are sorted
    // because the order the interface receives them in is not the contract.
    $reported = collect($response->json('user.permissions'))->sort()->values()->all();
    $granted = Permission::query()->pluck('name')->sort()->values()->all();

    expect($reported)->toBe($granted)
        ->and($granted)->toContain('settings.view', 'clients.view', 'affiliations.view');
});

it('creates no permission ahead of the module that enforces it', function (): void {
    // Every permission in the catalogue is enforced by a route that exists. A
    // permission nobody can reach is either a mistake or an invitation to trust
    // something that was never wired up, and both are worth failing over.
    $declared = Permission::query()->pluck('name')->all();

    $middleware = collect(app(Kernel::class)
        ->getGlobalMiddleware())
        ->merge(collect(app('router')->getRoutes())->flatMap(fn ($route) => $route->gatherMiddleware()));

    $guarded = $middleware
        ->filter(fn (string $m): bool => str_starts_with($m, 'can:'))
        ->map(fn (string $m): string => substr($m, 4))
        ->unique()
        ->values()
        ->all();

    // Every guarded permission exists, which is the direction that would fail
    // closed if somebody typed a permission name wrongly.
    expect(array_diff($guarded, $declared))->toBe([]);

    // And the catalogue holds nothing that belongs to no module at all: A01's
    // settings, A02's directory, and A03's financial permissions.
    $expected = ['settings.view'];
    $expected = array_merge($expected, [
        'clients.view', 'clients.create', 'clients.update', 'clients.change_status',
        'companies.view', 'companies.create', 'companies.update', 'companies.change_status',
        'relationships.view', 'relationships.manage',
        'affiliations.view', 'affiliations.manage',
        'social_security_entities.view', 'social_security_entities.manage',
    ]);
    $expected = array_merge($expected, [
        'periods.view', 'periods.create', 'periods.close', 'periods.reopen',
        'cutoffs.view', 'cutoffs.manage',
        'rates.view', 'rates.manage',
        'obligations.view', 'obligations.generate', 'obligations.adjust',
        'payments.view', 'payments.create', 'payments.allocate', 'payments.void',
        'receivables.view',
    ]);

    expect($declared)->toEqualCanonicalizing($expected);
});

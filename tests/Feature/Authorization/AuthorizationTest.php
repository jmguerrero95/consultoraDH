<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SettingsController;
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

    $this->actingAs($user)->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.permissions', ['settings.view'])
        ->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.remember_token');
});

it('creates no business permissions ahead of the modules that use them', function (): void {
    // Only the permission A01 actually enforces exists. Business permissions
    // arrive with their modules.
    expect(Permission::query()->pluck('name')->all())->toBe(['settings.view']);
});

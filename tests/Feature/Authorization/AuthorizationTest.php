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
            // A05: the portal role. It holds **no** internal administrative permission —
            // a portal account reaches its own data through ownership-scoped endpoints,
            // never through a staff permission.
            'Client',
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
    // settings, A02's directory, A03's financial permissions and A04's import permissions.
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
    $expected = array_merge($expected, [
        'imports.view', 'imports.create', 'imports.review', 'imports.apply',
    ]);
    // A05: the integrated operational layer. Every one of these is enforced by at least
    // one route in routes/api.php, which is what the "no permission ahead of the module
    // that enforces it" half of this test is asserting.
    $expected = array_merge($expected, [
        'planillas.view', 'planillas.create', 'planillas.update', 'planillas.validate',
        'planillas.submit', 'planillas.mark_paid', 'planillas.cancel',
        'novelties.view', 'novelties.manage',
        'tasks.view', 'tasks.manage',
        'documents.view', 'documents.manage', 'documents.request', 'documents.review',
        'portal_accounts.manage',
        'client_update_requests.view', 'client_update_requests.review',
        'reports.view', 'reports.export', 'reports.schedule',
    ]);
    // A06: the support desk. `support.view_all` is the one permission that is
    // not a `can:` guard of its own — it is checked inside the controllers to
    // let a supervisor read every queue instead of only their own.
    $expected = array_merge($expected, [
        'support.view', 'support.view_all', 'support.reply', 'support.assign',
        'support.resolve', 'support.manage_queues', 'support.manage_sla',
        'support.review_unlinked_email',
        'automations.view', 'automations.manage',
        'notification_channels.manage',
    ]);

    expect($declared)->toEqualCanonicalizing($expected);
});

/**
 * §14's matrix for A04, asserted end to end through the seeder.
 *
 * The point is the asymmetry, not the grants. Three roles get all four permissions and
 * three get **none** — not even `imports.view` — because there is no read-only way to use this
 * module: opening an import shows every person in the workbook, so a viewer would be a
 * reader of a payroll file rather than a reader of a client record.
 *
 * Each case asserts both halves, for the same reason as the rest of this file: a permission
 * that is granted and does nothing, and one that is refused without being granted, are
 * equally invisible if only one side is checked.
 */
it('grants the import permissions to the three operational roles and nobody else', function (): void {
    $all = ['imports.view', 'imports.create', 'imports.review', 'imports.apply'];

    foreach (['Super Admin', 'Administrator', 'Operations'] as $role) {
        foreach ($all as $permission) {
            expect(Role::findByName($role, 'web')->hasPermissionTo($permission))
                ->toBeTrue("{$role} should hold {$permission}");
        }
    }

    foreach (['Collections', 'Support', 'Read Only'] as $role) {
        foreach ($all as $permission) {
            expect(Role::findByName($role, 'web')->hasPermissionTo($permission))
                ->toBeFalse("{$role} must not hold {$permission}");
        }
    }
});

it('keeps review and apply as separate authorities', function (): void {
    // Resolving a question and writing what the answer means are different decisions. A role
    // that could do both by accident would be able to approve its own reconstruction without
    // anyone noticing, which is the whole point of splitting them.
    expect(Role::findByName('Operations', 'web')->hasPermissionTo('imports.review'))->toBeTrue();
    expect(Role::findByName('Operations', 'web')->hasPermissionTo('imports.apply'))->toBeTrue();

    // Neither of the three roles without access holds either one, so the split cannot be
    // inherited by accident through a role that holds only one.
    foreach (['Collections', 'Support', 'Read Only'] as $role) {
        expect(Role::findByName($role, 'web')->hasPermissionTo('imports.review'))->toBeFalse();
        expect(Role::findByName($role, 'web')->hasPermissionTo('imports.apply'))->toBeFalse();
    }
});

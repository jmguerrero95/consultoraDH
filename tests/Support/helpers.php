<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Helpers shared by the test suite.
 *
 * Defined as functions rather than on the base TestCase so that nothing test
 * only ends up on the application class.
 */

/**
 * Create the A01 role and permission set.
 *
 * Mirrors DatabaseSeeder, but without touching the shared seeder: the tests
 * need only the roles they exercise.
 */
function seedRoles(): void
{
    $permission = Permission::query()->firstOrCreate([
        'name' => 'settings.view',
        'guard_name' => 'web',
    ]);

    foreach ([
        'Super Admin',
        'Administrator',
        'Operations',
        'Collections',
        'Support',
        'Read Only',
    ] as $name) {
        Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    foreach (['Super Admin', 'Administrator'] as $name) {
        Role::findByName($name, 'web')->givePermissionTo($permission);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * Create a user holding one of the seeded roles.
 *
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    $user->assignRole($role);

    return $user->fresh();
}

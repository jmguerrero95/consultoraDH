<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
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
    // The real seeder, so a test can never pass against a role matrix that does
    // not exist in production. Seeding is cheap and the suite rolls back.
    app()->make(DatabaseSeeder::class)->run();

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

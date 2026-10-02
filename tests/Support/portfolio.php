<?php

declare(strict_types=1);

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Helpers for the A02 feature tests.
 *
 * Loaded once by tests/Pest.php rather than being a test file of its own.
 */

/**
 * The role matrix comes from the real seeder, so a test can never pass against a
 * matrix that does not exist in production.
 */
function seedPortfolioRoles(): void
{
    seedRoles();
}

/**
 * A user holding a role, ready to authenticate.
 */
function actingAsRole(string $role = 'Administrator'): User
{
    return userWithRole($role);
}

function clientPayload(array $overrides = []): array
{
    return array_merge([
        'document_type' => 'CC',
        'document_number' => '12345678',
        'first_names' => 'Ana María',
        'last_names' => 'Restrepo Pérez',
        'email' => 'ana@consultora-dh.test',
        'phone' => '3001234567',
        'address' => 'Calle 1 # 2-3',
        'city' => 'Medellín',
        'department' => 'Antioquia',
    ], $overrides);
}

function companyPayload(array $overrides = []): array
{
    return array_merge([
        'legal_name' => 'Comercializadora Ejemplo S.A.S.',
        'trade_name' => 'Comercializadora Ejemplo',
        'tax_id' => '900123456-3',
        'email' => 'contacto@ejemplo.test',
        'phone' => '6041234567',
        'address' => 'Carrera 1 # 2-3',
        'city' => 'Bogotá',
        'department' => 'Cundinamarca',
    ], $overrides);
}

/**
 * A catalogue entity of a given type.
 */
function entityOf(SocialSecurityEntityType $type, ?string $name = null): SocialSecurityEntity
{
    return SocialSecurityEntity::factory()->ofType($type)->create([
        'name' => $name ?? 'Entidad '.strtolower($type->value).' de prueba',
        'code' => strtoupper($type->value).'-'.random_int(1000, 9999),
    ]);
}

/**
 * A user holding exactly the permissions given and nothing else.
 *
 * A role of its own, built here and not in the seeder, because the seeded matrix
 * cannot prove that two permissions are independent: every seeded role that holds
 * one of them happens to hold the others, so a test using it would pass even if the
 * section were readable for the wrong reason.
 *
 * @param  list<string>  $permissions
 */
function userWithPermissions(array $permissions): User
{
    // Reused when the same set is asked for twice in one test: the tests compare a
    // role with and without one permission, which means the same role appears more
    // than once and its name has to be stable.
    $name = 'Prueba '.substr(md5(implode('|', $permissions)), 0, 8);

    $role = Role::query()->firstOrCreate(
        ['name' => $name, 'guard_name' => 'web'],
    );

    $role->syncPermissions($permissions);

    $user = User::factory()->create([
        'email' => 'prueba.'.substr(md5(implode('|', $permissions).microtime(true)), 0, 12).'@consultora-dh.test',
    ]);

    $user->assignRole($role);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

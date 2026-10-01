<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Baseline roles and the A01 permission set.
 *
 * Roles exist for the whole organisation and are stable. Permissions are added
 * as the business modules arrive, following the `<domain>.<action>` convention
 * already used here (for example `clients.view`, `payments.register`,
 * `periods.close`, `documents.review`, `reports.generate`).
 *
 * Only permissions that A01 actually enforces are created. Nothing is seeded
 * "for later".
 */
final class DatabaseSeeder extends Seeder
{
    /**
     * Roles, in descending order of access.
     *
     * @var list<string>
     */
    private const ROLES = [
        'Super Admin',
        'Administrator',
        'Operations',
        'Collections',
        'Support',
        'Read Only',
    ];

    /**
     * Roles allowed to inspect the application configuration.
     *
     * @var list<string>
     */
    private const SETTINGS_VIEWERS = [
        'Super Admin',
        'Administrator',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $settingsView = Permission::query()->firstOrCreate([
            'name' => 'settings.view',
            'guard_name' => 'web',
        ]);

        foreach (self::ROLES as $roleName) {
            Role::query()->firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);
        }

        foreach (self::SETTINGS_VIEWERS as $roleName) {
            Role::findByName($roleName, 'web')->givePermissionTo($settingsView);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

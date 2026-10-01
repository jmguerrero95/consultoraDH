<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Baseline roles and the A02 permission set.
 *
 * Roles exist for the whole organisation and are stable. Permissions follow the
 * `<domain>.<action>` convention: `<domain>.view` to read, and a specific
 * action for each thing that can be changed.
 *
 * A02 is the first task that distinguishes reading from doing. Before it there
 * was a single `settings.view` and one rule of thumb: an administrator could do
 * everything. A catalogue of clients, companies and affiliations has records that
 * are only read, records that are edited, and operations like a transfer that are
 * a single irreversible decision, and those deserve to be separately granted.
 *
 * Nothing is seeded "for later". Every permission created here is enforced
 * somewhere in routes/api.php.
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

    /**
     * The A02 permissions.
     *
     * @var list<string>
     */
    private const PERMISSIONS = [
        'clients.view',
        'clients.create',
        'clients.update',
        'clients.change_status',

        'companies.view',
        'companies.create',
        'companies.update',
        'companies.change_status',

        'relationships.view',
        'relationships.manage',

        'affiliations.view',
        'affiliations.manage',

        'social_security_entities.view',
        'social_security_entities.manage',
    ];

    /**
     * The role matrix.
     *
     * Written out in full rather than derived, because the point of this table is
     * to be read by a person deciding what somebody may do. A rule that computed
     * the matrix would hide the decisions inside the rule.
     *
     * @var array<string, list<string>>
     */
    private const MATRIX = [
        'Super Admin' => [
            'clients.view', 'clients.create', 'clients.update', 'clients.change_status',
            'companies.view', 'companies.create', 'companies.update', 'companies.change_status',
            'relationships.view', 'relationships.manage',
            'affiliations.view', 'affiliations.manage',
            'social_security_entities.view', 'social_security_entities.manage',
        ],

        'Administrator' => [
            'clients.view', 'clients.create', 'clients.update', 'clients.change_status',
            'companies.view', 'companies.create', 'companies.update', 'companies.change_status',
            'relationships.view', 'relationships.manage',
            'affiliations.view', 'affiliations.manage',
            'social_security_entities.view', 'social_security_entities.manage',
        ],

        // Day to day operation: the portfolio is administered, but the reference
        // catalogue is consulted rather than edited.
        'Operations' => [
            'clients.view', 'clients.create', 'clients.update',
            'companies.view', 'companies.create', 'companies.update',
            'relationships.view', 'relationships.manage',
            'affiliations.view', 'affiliations.manage',
            'social_security_entities.view',
        ],

        // Collection and billing: read the portfolio, do not change it.
        'Collections' => [
            'clients.view',
            'companies.view',
            'relationships.view',
        ],

        // Service: read only, and only what answers a customer's question.
        'Support' => [
            'clients.view',
            'companies.view',
            'relationships.view',
            'affiliations.view',
        ],

        'Read Only' => [
            'clients.view',
            'companies.view',
            'relationships.view',
            'affiliations.view',
            'social_security_entities.view',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ROLES as $roleName) {
            Role::query()->firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);
        }

        // The A01 settings permission is created alongside the A02 set so the
        // whole catalogue exists before any role is granted anything.
        foreach ([...self::PERMISSIONS, 'settings.view'] as $permissionName) {
            Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        // Every A02 permission the platform describes, plus the A01 settings one.
        $all = [...self::PERMISSIONS, 'settings.view'];

        foreach (self::MATRIX as $roleName => $permissions) {
            $role = Role::findByName($roleName, 'web');

            // Assigned rather than synced: a permission that exists in the matrix
            // but not yet in the catalogue must not be silently dropped.
            $role->givePermissionTo($permissions);
        }

        foreach (self::SETTINGS_VIEWERS as $roleName) {
            Role::findByName($roleName, 'web')->givePermissionTo('settings.view');
        }

        // Anything the matrix does not mention must not survive from a previous
        // seeding, or a role would quietly keep access nobody granted it.
        $known = array_values(array_unique($all));

        foreach (Role::query()->with('permissions')->get() as $role) {
            foreach ($role->permissions as $permission) {
                if (! in_array($permission->name, $known, true)) {
                    $role->revokePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

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
        'Client',
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

        /*
         | The A03 permissions.
         |
         | Sixteen, one per decision somebody can make about money. They are not
         | grouped into a single `finance.view` / `finance.manage` because the
         | decisions are genuinely different: reading a client's debt is not the
         | same authority as closing a month, and not the same as being able to
         | write off a debt.
         |
         | Fifteen were specified; `obligations.generate` is the sixteenth and was
         | added because it is the authority to decide *what somebody is billed*, which
         | none of the others expresses. It was not a flag inside `obligations.adjust`,
         | because correcting one obligation is not the same act as writing a month's
         | obligations in bulk. See docs/TASKS/A03.md §1.3.
         */
        'periods.view',
        'periods.create',
        'periods.close',
        'periods.reopen',

        'cutoffs.view',
        'cutoffs.manage',

        'rates.view',
        'rates.manage',

        'obligations.view',
        'obligations.generate',
        'obligations.adjust',

        'payments.view',
        'payments.create',
        'payments.allocate',
        'payments.void',

        'receivables.view',

        'imports.view',
        'imports.create',
        'imports.review',
        'imports.apply',

        /*
         | The A05 permissions.
         |
         | The integrated operational layer: planillas, novelties, tasks, documents,
         | the client portal, and reports. Each is its own domain with its own
         | view/manage split, because the decisions are genuinely different —
         | preparing a planilla is not the same authority as reviewing a document
         | request, and neither is the same as scheduling a report.
         */
        'planillas.view',
        'planillas.create',
        'planillas.update',
        'planillas.validate',
        'planillas.submit',
        'planillas.mark_paid',
        'planillas.cancel',

        'novelties.view',
        'novelties.manage',

        'tasks.view',
        'tasks.manage',

        'documents.view',
        'documents.manage',
        'documents.request',
        'documents.review',

        'portal_accounts.manage',
        'client_update_requests.view',
        'client_update_requests.review',

        'reports.view',
        'reports.export',
        'reports.schedule',

        /*
         | The A06 permissions.
         |
         | Support center, realtime, bidirectional email, PWA/Web Push, Telegram admin,
         | and general automation engine. Permissions are minimal and closed.
         */
        'support.view',
        'support.view_all',
        'support.reply',
        'support.assign',
        'support.resolve',
        'support.manage_queues',
        'support.manage_sla',
        'support.review_unlinked_email',

        'notification_channels.manage',

        'automations.view',
        'automations.manage',
    ];

    /**
     * The A04 permissions each role holds.
     *
     * ## Why this is a separate matrix and not four more entries above
     *
     * §14's reason for the split: the workbook is a file of mass PII and every role that may
     * touch it may also *reconstruct history from it*, which is a different and much broader
     * authority than reading a client's account. Collections, Support and Read Only get
     * nothing — not a read-only view either — because there is no read-only way to use this
     * module: even opening an import shows every person in the file.
     *
     * The three roles that do get it all get all four, deliberately. Splitting them would
     * create a person who can upload a file of national identifiers but not see what the parser
     * found in it, which is not a smaller authority, it is a blind one.
     *
     * @var array<string, list<string>>
     */
    private const MATRIX_A04 = [
        'Super Admin' => ['imports.view', 'imports.create', 'imports.review', 'imports.apply'],
        'Administrator' => ['imports.view', 'imports.create', 'imports.review', 'imports.apply'],
        'Operations' => ['imports.view', 'imports.create', 'imports.review', 'imports.apply'],
        'Collections' => [],
        'Support' => [],
        'Read Only' => [],
    ];

    /**
     * The A05 permissions each role holds.
     *
     * The Client role is deliberately absent from this matrix: it holds **zero**
     * internal administrative permissions. Portal access is ownership-scoped, not
     * permission-scoped — a client account sees only its own resources through
     * dedicated portal endpoints, never through staff endpoints.
     *
     * @var array<string, list<string>>
     */
    private const MATRIX_A05 = [
        'Super Admin' => [
            'planillas.view', 'planillas.create', 'planillas.update', 'planillas.validate',
            'planillas.submit', 'planillas.mark_paid', 'planillas.cancel',
            'novelties.view', 'novelties.manage',
            'tasks.view', 'tasks.manage',
            'documents.view', 'documents.manage', 'documents.request', 'documents.review',
            'portal_accounts.manage',
            'client_update_requests.view', 'client_update_requests.review',
            'reports.view', 'reports.export', 'reports.schedule',
        ],

        'Administrator' => [
            'planillas.view', 'planillas.create', 'planillas.update', 'planillas.validate',
            'planillas.submit', 'planillas.mark_paid', 'planillas.cancel',
            'novelties.view', 'novelties.manage',
            'tasks.view', 'tasks.manage',
            'documents.view', 'documents.manage', 'documents.request', 'documents.review',
            'portal_accounts.manage',
            'client_update_requests.view', 'client_update_requests.review',
            'reports.view', 'reports.export', 'reports.schedule',
        ],

        'Operations' => [
            'planillas.view', 'planillas.create', 'planillas.update', 'planillas.validate',
            'planillas.submit', 'planillas.mark_paid', 'planillas.cancel',
            'novelties.view', 'novelties.manage',
            'tasks.view', 'tasks.manage',
            'documents.view', 'documents.manage', 'documents.request', 'documents.review',
            'portal_accounts.manage',
            'client_update_requests.view', 'client_update_requests.review',
            'reports.view', 'reports.export', 'reports.schedule',
        ],

        'Collections' => [
            'planillas.view',
            'novelties.view',
            'tasks.view', 'tasks.manage',
            'documents.view',
            'reports.view', 'reports.export',
        ],

        'Support' => [
            'novelties.view', 'novelties.manage',
            'tasks.view', 'tasks.manage',
            'documents.view', 'documents.request', 'documents.review',
            'portal_accounts.manage',
            'reports.view',
        ],

        'Read Only' => [
            'planillas.view',
            'novelties.view',
            'tasks.view',
            'documents.view',
            'reports.view',
        ],
    ];

    /**
     * The A06 permissions each role holds.
     *
     * Client role gets zero A06 permissions. Portal access is ownership-based.
     * Support role gets queue-scoped support permissions (limited by queue membership
     * unless they also have support.view_all).
     *
     * @var array<string, list<string>>
     */
    private const MATRIX_A06 = [
        'Super Admin' => [
            'support.view', 'support.view_all', 'support.reply', 'support.assign',
            'support.resolve', 'support.manage_queues', 'support.manage_sla',
            'support.review_unlinked_email',
            'notification_channels.manage',
            'automations.view', 'automations.manage',
        ],

        'Administrator' => [
            'support.view', 'support.view_all', 'support.reply', 'support.assign',
            'support.resolve', 'support.manage_queues', 'support.manage_sla',
            'support.review_unlinked_email',
            'notification_channels.manage',
            'automations.view', 'automations.manage',
        ],

        'Operations' => [
            'support.view', 'support.reply', 'support.assign', 'support.resolve',
            'automations.view',
        ],

        'Collections' => [
            'support.view',
            'automations.view',
        ],

        'Support' => [
            'support.view', 'support.reply', 'support.assign', 'support.resolve',
            'automations.view',
        ],

        'Read Only' => [
            'support.view',
            'automations.view',
        ],
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

        // A03 read-only across the board: every financial screen is visible and
        // nothing about money can be changed.
        'Read Only' => [
            'clients.view',
            'companies.view',
            'relationships.view',
            'affiliations.view',
            'social_security_entities.view',

            'periods.view',
            'cutoffs.view',
            'rates.view',
            'obligations.view',
            'payments.view',
            'receivables.view',
        ],
    ];

    /**
     * The A03 permissions each role holds, stated separately so the money
     * decisions can be read without scanning the whole matrix.
     *
     * The two deliberate asymmetries:
     *
     *  - Operations closes periods but does **not** reopen them. Closing is the
     *    ordinary end of a billing month. Reopening withdraws a settled statement
     *    and leaves a reason in the audit trail, which is a different authority and
     *    belongs with an administrator.
     *
     *  - Collections can void a payment but cannot adjust an obligation. Cancelling
     *    a payment that was recorded in error is a collections action; reducing what
     *    somebody is owed is a financial correction.
     *
     * @var array<string, list<string>>
     */
    private const MATRIX_A03 = [
        'Super Admin' => [
            'periods.view', 'periods.create', 'periods.close', 'periods.reopen',
            'cutoffs.view', 'cutoffs.manage',
            'rates.view', 'rates.manage',
            'obligations.view', 'obligations.generate', 'obligations.adjust',
            'payments.view', 'payments.create', 'payments.allocate', 'payments.void',
            'receivables.view',
        ],

        'Administrator' => [
            'periods.view', 'periods.create', 'periods.close', 'periods.reopen',
            'cutoffs.view', 'cutoffs.manage',
            'rates.view', 'rates.manage',
            'obligations.view', 'obligations.generate', 'obligations.adjust',
            'payments.view', 'payments.create', 'payments.allocate', 'payments.void',
            'receivables.view',
        ],

        'Operations' => [
            'periods.view', 'periods.create', 'periods.close',
            'cutoffs.view', 'cutoffs.manage',
            'rates.view', 'rates.manage',
            'obligations.view', 'obligations.generate', 'obligations.adjust',
            'payments.view',
            'receivables.view',
        ],

        'Collections' => [
            'periods.view',
            'obligations.view',
            'payments.view', 'payments.create', 'payments.allocate', 'payments.void',
            'receivables.view',
        ],

        'Support' => [
            'periods.view',
            'obligations.view',
            'payments.view',
            'receivables.view',
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

        // Every permission the platform describes, plus the A01 settings one. Both
        // matrices are included, because the revocation pass below treats anything
        // missing from this list as a leftover from a previous seeding: leaving the
        // A03 half out would have quietly undone every grant made a few lines above.
        $all = [
            ...self::PERMISSIONS,
            ...array_merge(...array_values(self::MATRIX_A03)),
            ...array_merge(...array_values(self::MATRIX_A04)),
            ...array_merge(...array_values(self::MATRIX_A05)),
            ...array_merge(...array_values(self::MATRIX_A06)),
            'settings.view',
        ];

        foreach (self::MATRIX as $roleName => $permissions) {
            $role = Role::findByName($roleName, 'web');

            // Assigned rather than synced: a permission that exists in the matrix
            // but not yet in the catalogue must not be silently dropped.
            $role->givePermissionTo($permissions);
        }

        // The A03 matrix is applied as its own pass so the two can be read, and
        // changed, independently of each other.
        foreach (self::MATRIX_A03 as $roleName => $permissions) {
            Role::findByName($roleName, 'web')->givePermissionTo($permissions);
        }

        // The A04 matrix is a third pass, for the same reason the A03 one is: the import
        // module's reach has to be readable on its own, without scanning the other two.
        foreach (self::MATRIX_A04 as $roleName => $permissions) {
            if ($permissions === []) {
                continue;
            }

            Role::findByName($roleName, 'web')->givePermissionTo($permissions);
        }

        // The A05 matrix is a fourth pass. The Client role is intentionally absent: it
        // holds no staff permissions, and the revocation pass below removes any that
        // might have been granted by a previous seeding.
        foreach (self::MATRIX_A05 as $roleName => $permissions) {
            Role::findByName($roleName, 'web')->givePermissionTo($permissions);
        }

        // The A06 matrix is a fifth pass. The Client role is intentionally absent: it
        // holds no staff permissions, and the revocation pass below removes any that
        // might have been granted by a previous seeding.
        foreach (self::MATRIX_A06 as $roleName => $permissions) {
            Role::findByName($roleName, 'web')->givePermissionTo($permissions);
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

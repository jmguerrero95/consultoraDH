<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\LegacyImport;
use App\Models\LegacyImportRow;
use App\Support\Database\SchemaConstraint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * §21: the migration matrix.
 *
 * Four states have to hold, and each is a different failure:
 *
 *  1. **Fresh.** A database built from nothing reaches the expected columns, checks and
 *     indexes. This is what a new environment gets.
 *  2. **Upgrade.** A database that already has A02 and A03 data reaches the same schema with
 *     that data intact — §8.3 adds columns to tables A02 owns, and the risk is in what those
 *     columns default to for rows that already existed.
 *  3. **Rollback.** Undoing A04 leaves the A02 and A03 tables exactly as they were, including
 *     their rows. §21 asks for "sin pérdida/corrupción de tablas anteriores".
 *  4. **Re-apply.** Rolling forward again reaches the same schema, so a rollback is not a trap.
 *
 * These run against the **test** database. `tests/Pest.php` refuses to run the suite against
 * the development one, and this file refuses to do anything at all if it is asked to.
 */

/** The A04 migrations, in order. */
function a04Migrations(): array
{
    return [
        '2026_10_04_100000_add_date_precision_to_historical_tables',
        '2026_10_04_110000_create_legacy_imports_table',
        '2026_10_04_120000_create_legacy_import_rows_table',
        '2026_10_04_130000_create_legacy_import_issues_table',
        '2026_10_04_140000_create_legacy_import_actions_table',
        '2026_10_04_150000_create_import_source_mappings_table',
    ];
}

function assertTestDatabase(): void
{
    $connection = config('database.default');
    $testing = config('database.connections.testing.database');
    $development = config('database.connections.pgsql.database');

    expect($connection)->toBe('testing');

    if ($testing === null || $development === null) {
        return;
    }

    // The same guard `tests/Pest.php` applies, restated because `migrate:fresh` destroys
    // whatever it is pointed at and this file calls it.
    expect($testing)->not->toBe($development);
}

function migratePath(string $migration): string
{
    return database_path('migrations/'.$migration.'.php');
}

/**
 * Roll the A04 migrations back, leaving A01–A03 in place.
 *
 * By path rather than by `--step`, because the step count depends on what else has run and a
 * `--step` of six would happily undo an A03 migration on a database where A04 had already been
 * rolled back. Naming the files makes the rollback mean exactly "A04".
 */
function rollbackA04(): void
{
    foreach (array_reverse(a04Migrations()) as $migration) {
        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/'.$migration.'.php',
            '--force' => true,
        ]);
    }
}

beforeEach(function (): void {
    assertTestDatabase();
});

it('reaches the expected schema on a fresh database', function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
    // Every A04 table exists.
    foreach ([
        'legacy_imports',
        'legacy_import_rows',
        'legacy_import_issues',
        'legacy_import_actions',
        'import_source_mappings',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} is missing after a fresh migrate");
    }

    // §8.3's precision columns, on the two A02 tables that own history.
    foreach (['client_company_assignments', 'client_affiliations'] as $table) {
        expect(Schema::hasColumn($table, 'started_on_precision'))->toBeTrue();
        expect(Schema::hasColumn($table, 'ended_on_precision'))->toBeTrue();
    }

    // The constraints that make the schema mean something.
    //
    // Written as `array_diff` and asserted once, because `toContain($needle, $message)` reads
    // the second argument as another needle — so a passing check with a message attached would
    // have failed, and a failing one would have named the wrong thing.
    $tables = [
        'legacy_imports',
        'legacy_import_rows',
        'legacy_import_issues',
        'legacy_import_actions',
        'import_source_mappings',
        'client_affiliations',
        'client_company_assignments',
    ];

    $placeholders = implode(', ', array_fill(0, count($tables), '?'));

    // Joined on `pg_class` and filtered by `relname` rather than by `conrelid::regclass::text`:
    // the cast form silently matches nothing when the patterns are bound parameters, which
    // fails as "every constraint is missing" and reads as a schema problem rather than a query
    // one. Table names are also more honest than substrings.
    $constraints = array_column(DB::select(
        "SELECT c.conname FROM pg_constraint c
         JOIN pg_class t ON t.oid = c.conrelid
         WHERE t.relname IN ({$placeholders})",
        $tables,
    ), 'conname');

    $expected = [
        // An import that says `applied` must have a moment, and vice versa.
        'legacy_imports_status_check',
        'legacy_imports_applied_shape_check',
        'legacy_imports_profile_check',
        'legacy_imports_file_size_check',

        'legacy_import_rows_state_check',
        'legacy_import_rows_affiliation_date_check',
        'legacy_import_rows_amount_check',
        'legacy_import_rows_risk_check',
        'legacy_import_rows_sheet_month_check',
        'legacy_import_rows_row_number_check',

        'legacy_import_issues_code_check',
        'legacy_import_issues_severity_check',
        'legacy_import_issues_resolution_check',

        'legacy_import_actions_type_check',
        'legacy_import_actions_state_check',
        'legacy_import_actions_target_check',
        'legacy_import_actions_skip_check',
        'legacy_import_actions_ordinal_check',

        // §8.3: a date and its precision have to agree, or one of them is a lie.
        'affiliations_started_precision_check',
        'affiliations_ended_precision_check',
        'affiliations_started_precision_coherent_check',
        'assignments_started_precision_check',
        'assignments_ended_precision_check',
    ];

    expect(array_values(array_diff($expected, $constraints)))->toBe([]);

    // §5.4's plan uniqueness: the same action cannot be planned twice in one import.
    $indexes = collect(DB::select(
        "SELECT indexname FROM pg_indexes WHERE tablename = 'legacy_import_actions'",
    ))->pluck('indexname')->all();

    expect($indexes)->toContain('legacy_import_actions_batch_unique');
});

it('upgrades a database that already has A02 and A03 data', function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);

    // Roll A04 back so the database is at the pre-A04 baseline.
    rollbackA04();

    expect(Schema::hasColumn('client_affiliations', 'started_on_precision'))->toBeFalse();
    expect(Schema::hasTable('legacy_imports'))->toBeFalse();

    // The baseline data is created **here**, while the database is at the baseline, because
    // that is what "upgrade with existing data" means.
    //
    // Written with the query builder rather than with the models, and that is not a
    // convenience. The A04 models declare `started_on_precision` and their `saving` hook fills
    // it, so an Eloquent insert against a pre-A04 schema fails on a column that does not exist
    // yet. That is correct behaviour — nothing runs application code inside a migration window —
    // and using the models here would have tested a question nobody asked.
    $now = now();

    $clientId = DB::table('clients')->insertGetId([
        'document_type' => 'CC',
        'document_number' => '10101010',
        'first_names' => 'JUAN',
        'last_names' => 'PEREZ',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $companyId = DB::table('companies')->insertGetId([
        'legal_name' => 'ANDINA S.A.S.',
        'tax_id' => '900123456',
        'verification_digit' => '3',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $assignmentId = DB::table('client_company_assignments')->insertGetId([
        'client_id' => $clientId,
        'company_id' => $companyId,
        'started_on' => '2024-01-01',
        'job_title' => 'AUXILIAR',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $entityId = DB::table('social_security_entities')->insertGetId([
        'type' => 'EPS',
        'name' => 'SALUD TOTAL',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // One affiliation with a start date and one without, because §8.3's correction is about the
    // second: a row whose start nobody recorded must come out of the migration as `unknown`
    // rather than as a `day` it never had.
    $affiliationId = DB::table('client_affiliations')->insertGetId([
        'client_id' => $clientId,
        'social_security_entity_id' => $entityId,
        'client_company_assignment_id' => $assignmentId,
        'type' => 'EPS',
        'started_on' => '2024-01-01',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $undatedEntityId = DB::table('social_security_entities')->insertGetId([
        'type' => 'AFP',
        'name' => 'PORVENIR',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('client_affiliations')->insert([
        'client_id' => $clientId,
        'social_security_entity_id' => $undatedEntityId,
        'type' => 'AFP',
        'started_on' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Roll forward.
    Artisan::call('migrate', ['--force' => true]);

    // The rows are intact, read raw so the assertion is about the database and not about a
    // model's hydration.
    expect(DB::table('clients')->count())->toBe(1);
    expect(DB::table('companies')->count())->toBe(1);
    expect(DB::table('client_company_assignments')->count())->toBe(1);
    expect(DB::table('client_affiliations')->count())->toBe(2);

    expect(DB::table('client_company_assignments')->find($assignmentId)->started_on_precision)->toBe('day');
    expect(DB::table('client_affiliations')->find($affiliationId)->started_on_precision)->toBe('day');

    // The row whose start nobody recorded must come out as `unknown`. A `day` here would be a
    // row claiming an exact date it never had, which is the whole failure §8.3's column exists
    // to prevent — and it is why the migration corrects NULL starts rather than relying on the
    // column default.
    $undated = DB::table('client_affiliations')->whereNull('started_on')->first();

    expect($undated)->not->toBeNull();
    expect($undated->started_on_precision)->toBe('unknown');

    // And a relationship that is still open must have no end precision, for the same reason.
    expect(DB::table('client_company_assignments')->find($assignmentId)->ended_on)->toBeNull();
    expect(DB::table('client_company_assignments')->find($assignmentId)->ended_on_precision)->toBeNull();
});

it('rolls A04 back without touching the tables A02 owns', function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);

    $client = Client::factory()->create([
        'document_type' => 'CC',
        'document_number' => '20202020',
    ]);
    $company = Company::factory()->create(['tax_id' => '900987654']);
    $assignment = ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'started_on' => '2024-02-01',
    ]);

    // Something for A04's own tables to hold, so the cascade is exercised.
    $import = LegacyImport::query()->create([
        'uuid' => (string) Str::uuid(),
        'profile' => 'blinden_legacy_monthly_v1',
        'original_filename' => 'source.xlsx',
        'stored_path' => 'imports/x/source.xlsx',
        'sha256' => str_repeat('c', 64),
        'file_size' => 10,
        'status' => 'uploaded',
        'summary' => [],
    ]);

    LegacyImportRow::query()->create([
        'legacy_import_id' => $import->id,
        'sheet_name' => 'ENERO 2026',
        'sheet_month' => '2026-01-01',
        'source_row_number' => 1,
        'block_index' => 0,
        'company_block_key' => 'x',
        'normalized_payload' => [],
        'fingerprint' => str_repeat('d', 64),
        // NOT NULL since A04-R1: a staged line's identity by position is what §13's provenance
        // and §18's issue identity are built on, so a row without one is not a row.
        'source_key' => LegacyImportRow::sourceKeyFor('ENERO 2026', '2026-01', 1, 0),
        'parse_state' => 'staged',
    ]);

    rollbackA04();

    // A04's tables are gone, with their rows.
    foreach ([
        'legacy_imports',
        'legacy_import_rows',
        'legacy_import_issues',
        'legacy_import_actions',
        'import_source_mappings',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} survived the rollback");
    }

    // The precision columns are gone...
    expect(Schema::hasColumn('client_affiliations', 'started_on_precision'))->toBeFalse();
    expect(Schema::hasColumn('client_company_assignments', 'ended_on_precision'))->toBeFalse();

    // ...and A02's data is untouched. §21: "rollback de migraciones A04 sin pérdida/corrupción
    // de tablas anteriores".
    expect(Client::query()->count())->toBe(1);
    expect(Company::query()->count())->toBe(1);
    expect(ClientCompanyAssignment::query()->count())->toBe(1);

    $survivor = $assignment->fresh();

    expect($survivor)->not->toBeNull();
    expect($survivor->client_id)->toBe($client->id);
    expect($survivor->started_on?->format('Y-m-d'))->toBe('2024-02-01');

    // The client itself kept its identity and gained nothing.
    expect($client->fresh()->document_number)->toBe('20202020');
    expect($company->fresh()->tax_id)->toBe('900987654');
});

it('re-applies cleanly after a rollback', function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);

    $first = DB::select(
        "SELECT column_name, data_type FROM information_schema.columns
         WHERE table_name = 'client_affiliations' ORDER BY column_name",
    );

    rollbackA04();

    Artisan::call('migrate', ['--force' => true]);

    $second = DB::select(
        "SELECT column_name, data_type FROM information_schema.columns
         WHERE table_name = 'client_affiliations' ORDER BY column_name",
    );

    // A rollback that is not exactly reversible would leave the second schema different from the
    // first, and the difference would only show up the next time somebody ran it.
    expect($second)->toEqual($first);

    expect(Schema::hasTable('legacy_imports'))->toBeTrue();
    expect(Schema::hasTable('legacy_import_actions'))->toBeTrue();
});

it('registers every A04 unique constraint for the seeder', function (): void {
    // A03 established that a table's uniqueness is a *named* constraint so the seeder can drop
    // and recreate it idempotently. A04's three have to be declared in the same registry, and
    // the uuid one is not among them because `uuid` is unique by a column constraint rather
    // than an index — naming it here would make the seeder look for an index that is not one.
    $registered = SchemaConstraint::all();

    expect($registered)->toContain(SchemaConstraint::IMPORT_SHA256_APPLIED)
        ->and($registered)->toContain(SchemaConstraint::IMPORT_ACTION_BATCH)
        ->and($registered)->toContain(SchemaConstraint::IMPORT_MAPPING_SOURCE);

    $present = collect(DB::select(
        "SELECT indexname FROM pg_indexes WHERE indexname LIKE 'legacy_import%' OR indexname LIKE 'import_source%'",
    ))->pluck('indexname')->all();

    expect($present)->toContain(SchemaConstraint::IMPORT_SHA256_APPLIED)
        ->and($present)->toContain(SchemaConstraint::IMPORT_ACTION_BATCH)
        ->and($present)->toContain(SchemaConstraint::IMPORT_MAPPING_SOURCE);
});

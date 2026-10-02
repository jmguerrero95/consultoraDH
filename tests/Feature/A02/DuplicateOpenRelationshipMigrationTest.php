<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The migration that refuses duplicate open relationships.
 *
 * A unique index cannot be created while duplicates exist, and the interesting
 * decision is what happens then. The alternatives were all worse than refusing:
 *
 *  - close one of the two rows automatically, which invents an end date nobody
 *    knows and silently deletes a period that may be the real one;
 *  - delete the older row, which is the same guess with less honesty;
 *  - skip the index, which would leave the database able to accept what the domain
 *    refuses, and the two would disagree for ever after.
 *
 * So it reports the offending pairs, naming the client and the company of each,
 * and stops. Somebody who knows the employment history decides.
 *
 * The index is exercised in `RelationshipTest`; this file is about the moment the
 * data is not yet clean.
 */
function duplicateMigration(): object
{
    $path = database_path('migrations/2026_10_01_240000_forbid_duplicate_open_relationship.php');

    $migration = require $path;

    // Each test gets a fresh instance: a migration object is not reusable, and one
    // that remembered a previous run would decide based on stale data.
    return $migration;
}

/**
 * Put the database back to how it was before the migration was written.
 *
 * The index is dropped by hand because that is precisely the state these tests are
 * about. PostgreSQL rolls the change back with the transaction each test runs in,
 * so the suite is left the way it was found.
 */
function withoutTheDuplicateIndex(): void
{
    DB::statement('DROP INDEX IF EXISTS assignments_one_open_client_company_unique');
}

/**
 * Two open rows for the same client and company, which the index cannot accept.
 */
/**
 * Try to insert a second open row for the same pair, and report what happened.
 *
 * The insert goes into a nested transaction so it becomes a savepoint: PostgreSQL
 * refuses every statement in a transaction whose statement failed, and the tests
 * here still have statements to run afterwards.
 */
function attemptSecondOpenRow(int $clientId, int $companyId): ?QueryException
{
    try {
        DB::transaction(function () use ($clientId, $companyId): void {
            ClientCompanyAssignment::query()->create([
                'client_id' => $clientId,
                'company_id' => $companyId,
                'started_on' => '2025-01-01',
                'status' => 'active',
            ]);
        });
    } catch (QueryException $e) {
        return $e;
    }

    return null;
}

function seedDuplicateOpenRelationship(): ClientCompanyAssignment
{
    withoutTheDuplicateIndex();

    $client = Client::factory()->create();
    $company = Company::factory()->create();

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'ended_on' => null,
    ]);

    $second = ClientCompanyAssignment::query()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'started_on' => '2024-01-01',
        'status' => 'active',
    ]);

    // The index does not exist while this is seeded, which is the whole point: this
    // is what the database looked like before the migration was written.
    expect(ClientCompanyAssignment::query()
        ->where('client_id', $client->id)
        ->where('company_id', $company->id)
        ->whereNull('ended_on')
        ->count())->toBe(2);

    return $second;
}

it('refuses to run while duplicates exist, and names them', function (): void {
    $second = seedDuplicateOpenRelationship();

    $migration = duplicateMigration();

    $thrown = null;

    try {
        $migration->up();
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toContain('relaciones abiertas duplicadas')
        // Both the client and the company are named, so the operator can find the
        // rows without reconstructing which pair is meant.
        ->and($thrown->getMessage())->toContain('client_id='.$second->client_id)
        ->and($thrown->getMessage())->toContain('company_id='.$second->company_id)
        // And it says plainly that nothing was changed.
        ->and($thrown->getMessage())->toContain('no se borran ni se cierran automáticamente');

    // Nothing was repaired on the way out.
    expect(ClientCompanyAssignment::query()
        ->where('client_id', $second->client_id)
        ->where('company_id', $second->company_id)
        ->whereNull('ended_on')
        ->count())->toBe(2);
});

it('runs once the conflict is closed by hand', function (): void {
    $second = seedDuplicateOpenRelationship();

    // What the message asks the operator to do, done explicitly: on a date the
    // row's own period allows.
    ClientCompanyAssignment::query()
        ->whereKey($second->getKey())
        ->update(['ended_on' => '2024-06-30']);

    $migration = duplicateMigration();

    $migration->up();

    // The index is there now, and it holds.
    expect(DB::selectOne(
        "select count(*) as total from pg_indexes where indexname = 'assignments_one_open_client_company_unique'"
    )->total)->toBe(1);

    $refused = attemptSecondOpenRow($second->client_id, $second->company_id);

    expect($refused)->toBeInstanceOf(QueryException::class)
        ->and($refused->getMessage())->toContain('assignments_one_open_client_company_unique');

    $migration->down();
});

it('leaves the rows alone when it is rolled back', function (): void {
    $second = seedDuplicateOpenRelationship();

    // Closed by hand first, because that is the only way the migration can run at
    // all, and this test is about what happens afterwards.
    ClientCompanyAssignment::query()
        ->whereKey($second->getKey())
        ->update(['ended_on' => '2024-06-30']);

    $migration = duplicateMigration();

    $migration->up();

    // Before the index existed two open rows were writable. Rolling back restores
    // that, and only that: nothing about the data changes, because nothing about
    // the data was changed.
    $migration->down();

    // Reopened once the second row is closed, so the pair has no open row at all
    // and the insert succeeds whether or not the index exists.
    ClientCompanyAssignment::query()
        ->whereKey($second->getKey())
        ->update(['ended_on' => null]);

    $refused = attemptSecondOpenRow($second->client_id, $second->company_id);

    // Nothing refuses it once the index is gone: the rule was the index, and the
    // data itself is untouched by either direction.
    expect($refused)->toBeNull();

    expect(DB::selectOne(
        "select count(*) as total from pg_indexes where indexname = 'assignments_one_open_client_company_unique'"
    )->total)->toBe(0);

    // No attempt is made to leave the index in place afterwards: the duplicate this
    // test seeded is still open, so `up()` would rightly refuse. Each test runs in
    // a transaction that PostgreSQL rolls back, which restores both the rows and the
    // index for the tests that follow.
});

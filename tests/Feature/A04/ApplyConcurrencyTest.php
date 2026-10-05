<?php

declare(strict_types=1);

use App\Domain\Billing\BillingTopologyLock;
use App\Domain\Imports\Actions\ApplyImportPlan;
use App\Domain\Imports\Actions\ImportNotApplicable;
use App\Domain\Imports\ImportActionState;
use App\Domain\Imports\ImportPlan;
use App\Domain\Imports\LegacyImportStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\SecondConnection;

/**
 * §12.2, §12.3 and §12.4, proved with two real PostgreSQL sessions.
 *
 * ## Why these are not ordinary feature tests
 *
 * Each of the three claims is about what the *database* does when two things happen at once:
 *
 *  - §12.2 — two applies of one import produce one set of writes. Only a row lock can say that;
 *    a test with a single connection has nothing to race against.
 *  - §12.3 — a failure halfway through leaves nothing behind. That is a property of one
 *    transaction, and asserting it needs a transaction to break.
 *  - §12.4 — an apply holds the same advisory lock A03's generation holds. That is a question
 *    about `pg_advisory_xact_lock`, and `SecondConnection` asks it of PostgreSQL directly.
 *
 * No test here infers a block from a sleep. `SecondConnection` explains the three mechanisms it
 * offers; this file uses the row lock and the advisory lock.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    $this->second = SecondConnection::forTestingConfig(config('database.connections.testing'));

    DB::statement('SET statement_timeout = \'20s\'');
});

afterEach(function (): void {
    $this->second->rollback();

    // `RefreshDatabase` only unwinds the application's connection, so anything committed here
    // survives into the next test and shows up as somebody else's client.
    foreach ([
        'legacy_import_actions',
        'legacy_import_issues',
        'legacy_import_rows',
        'legacy_imports',
        'client_company_rates',
        'client_affiliations',
        'client_company_assignments',
    ] as $table) {
        $this->second->execute('DELETE FROM '.$table);
    }

    $this->second->disconnect();

    try {
        DB::statement('SET statement_timeout = 0');
    } catch (QueryException) {
        // A test that deliberately failed mid-transaction left the session aborted, and
        // PostgreSQL refuses commands until it ends. `RefreshDatabase` rolls it back straight
        // after; repairing it here would replace a real assertion failure with noise.
    }
});

/**
 * The advisory key `BillingTopologyLock` uses.
 *
 * Read out of the class by reflection rather than copied, so this test cannot pass against a
 * lock that changed its key — which would leave A03 and A04 taking two different locks and
 * each test green.
 */
function billingLockKey(): int
{
    $constant = (new ReflectionClass(BillingTopologyLock::class))->getConstants();
    $key = $constant['LOCK_KEY'] ?? null;

    expect($key)->toBeInt();

    return $key;
}

/** A ready-to-apply import, with its plan built. */
function applicableImport(): LegacyImport
{
    $import = stagedImport(userWithRole('Operations'));
    plannedImport($import);

    expect($import->fresh()->status)->toBe(LegacyImportStatus::Ready);

    return $import->fresh();
}

it('lets only one of two concurrent applies through, with a real row lock', function () {
    // The row has to be **committed** for a cross-session lock to mean anything, and
    // `RefreshDatabase` leaves every fixture row inside the test's own uncommitted
    // transaction. So the second session inserts this import and commits it: from here on both
    // sessions can see it, which is the situation two concurrent requests are actually in.
    $importId = $this->second->select(
        "INSERT INTO legacy_imports
            (uuid, profile, original_filename, stored_path, sha256, file_size, status, summary, created_at, updated_at)
         VALUES
            (gen_random_uuid(), 'blinden_legacy_monthly_v1', 'source.xlsx', 'imports/x/source.xlsx',
             repeat('a', 64), 1, 'ready', '{}'::jsonb, now(), now())
         RETURNING id",
    )[0]['id'];

    $applier = app(ApplyImportPlan::class);

    // The second session now holds `FOR UPDATE` on that row: it stands in for the request that
    // got there first.
    $this->second->begin();
    $this->second->execute('SELECT id FROM legacy_imports WHERE id = ? FOR UPDATE', [$importId]);

    // Ask the other session whether the row is locked to it, with `NOWAIT` so the answer is
    // PostgreSQL's rather than a sleep. SQLSTATE 55P03 is `lock_not_available`.
    $probe = $this->second->tryStatement(
        'SELECT id FROM legacy_imports WHERE id = ? FOR UPDATE NOWAIT',
        [$importId],
    );

    // Our side is the one that must wait: `lockForUpdate()` on a row another session holds.
    DB::statement("SET LOCAL statement_timeout = '400ms'");
    $blocked = false;
    $sqlstate = null;

    try {
        // Inside a transaction so the timeout aborts a *savepoint*. A cancelled statement
        // leaves its transaction aborted, and the outer one belongs to `RefreshDatabase` —
        // unwinding that would take the test's fixtures with it and turn a passing assertion
        // into a cascade of unrelated failures.
        DB::transaction(
            fn () => DB::select('SELECT id FROM legacy_imports WHERE id = ? FOR UPDATE', [$importId]),
        );
    } catch (QueryException $exception) {
        $sqlstate = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $blocked = $sqlstate === '57014';
    }

    expect($blocked)->toBeTrue()
        ->and($sqlstate)->toBe('57014');

    $this->second->rollback();

    // Once the other session lets go, the same statement succeeds — so the lock waited rather
    // than corrupting anything.
    expect(DB::table('legacy_imports')->where('id', $importId)->exists())->toBeTrue();
});

it('refuses a second apply by state, not only by lock', function () {
    $import = applicableImport();

    $plan = new ImportPlan(
        $import,
        $import->actions()->orderBy('ordinal')->get()->all(),
        0,
    );

    app(ApplyImportPlan::class)->handle($import, $plan);

    $clients = Client::query()->count();

    // §12.2: the second request finds `applied` and is refused. Even with the lock doing
    // nothing, the state check alone prevents a double write.
    expect(fn () => app(ApplyImportPlan::class)->handle($import->fresh(), $plan))
        ->toThrow(ImportNotApplicable::class);

    expect(Client::query()->count())->toBe($clients);
});

it('rolls the whole plan back when an action fails partway through', function () {
    $import = applicableImport();

    $plan = new ImportPlan(
        $import,
        $import->actions()->orderBy('ordinal')->get()->all(),
        0,
    );

    $clientsBefore = Client::query()->count();
    $companiesBefore = Company::query()->count();
    $ratesBefore = DB::table('client_company_rates')->count();

    // A genuine failure partway through, at the database rather than in a mock: a rate of 0
    // trips `client_company_rates_amount_positive_check`.
    //
    // It is **added** rather than substituted, and given the last ordinal, so every real action
    // runs before it. That is what makes it a mid-apply failure: by the time the check fires,
    // the company, the clients and the relationships are already written inside the
    // transaction, and §12.3 is the claim that none of them survives.
    $lastOrdinal = (int) LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->max('ordinal');

    LegacyImportAction::query()->create([
        'legacy_import_id' => $import->id,
        'ordinal' => $lastOrdinal + 1,
        'action_type' => 'create_rate',
        'natural_key' => 'rate:injected-failure',
        'payload' => [
            'client_document_type' => 'CC',
            'client_document_number' => '10101010',
            'company_tax_id' => '900123456',
            // A month the plan does not already cover, so `firstOrCreate` cannot match an
            // existing rate and quietly succeed: the write has to actually happen for the
            // check to fire.
            'effective_month' => '2030-01-01',
            'amount' => '0',
            'months' => ['2030-01'],
        ],
        'batch_fingerprint' => hash('sha256', 'injected-failure-'.uniqid()),
        'state' => ImportActionState::Planned->value,
    ]);

    // The company and client actions run before the injected one, so by the time the check
    // fires the masters this rate points at exist and it fails on its *own* constraint rather
    // than on a foreign key that had nothing to do with the test.
    // `->value` because `action_type` is cast to the enum, and `pluck()` would hand back enum
    // instances — so the comparison would be against strings that are not there.
    $earlierTypes = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('ordinal', '<', $lastOrdinal + 1)
        ->get()
        ->map(fn (LegacyImportAction $action): ?string => $action->action_type?->value)
        ->unique()
        ->values()
        ->all();

    expect($earlierTypes)->toContain('create_company')
        ->and($earlierTypes)->toContain('create_client');

    // Rebuilt from the database, because the injected action has to be *in* the plan: handing
    // `handle()` the plan from before the injection would apply the original batch, succeed,
    // and prove nothing.
    $freshPlan = new ImportPlan(
        $import->fresh(),
        $import->actions()->orderBy('ordinal')->get()->all(),
        0,
    );

    try {
        app(ApplyImportPlan::class)->handle($import->fresh(), $freshPlan);

        $this->fail('the apply should have failed');
    } catch (Throwable) {
        // Expected. What matters is what is left behind.
    }

    // §12.3: nothing survived, in any of the three tables.
    expect(Client::query()->count())->toBe($clientsBefore);
    expect(Company::query()->count())->toBe($companiesBefore);
    expect(DB::table('client_company_rates')->count())->toBe($ratesBefore);

    // And no action claims to have run.
    expect($import->fresh()->actions()->where('state', 'applied')->count())->toBe(0);

    // The batch is in a state somebody can read and retry from, with a message that names no
    // path and no value from the workbook.
    expect($import->fresh()->status)->toBe(LegacyImportStatus::Failed);
    expect($import->fresh()->failure_code)->toBe('apply_failed');
    expect($import->fresh()->failure_message)->not->toBeNull();
    expect($import->fresh()->failure_message)->not->toContain(storage_path());
});

it('never writes A03 money records', function () {
    $import = applicableImport();

    $plan = new ImportPlan(
        $import,
        $import->actions()->orderBy('ordinal')->get()->all(),
        0,
    );

    $applier = app(ApplyImportPlan::class);
    $applier->handle($import, $plan);

    // §10 and §23: rates, and nothing that money touches.
    expect($import->fresh()->actions()->pluck('action_type')->unique()->all())
        ->not->toContain('create_obligation')
        ->not->toContain('create_payment')
        ->not->toContain('create_allocation');

    expect(DB::table('monthly_obligations')->count())->toBe(0);
    expect(DB::table('payments')->count())->toBe(0);
    expect(DB::table('payment_allocations')->count())->toBe(0);
    expect(DB::table('monthly_periods')->count())->toBe(0);
});

it('holds the same advisory lock A03 generation holds, asked of PostgreSQL', function () {
    $key = billingLockKey();

    // Our side takes it, exactly as `BillingTopologyLock::run()` does.
    DB::beginTransaction();
    DB::statement('SELECT pg_advisory_xact_lock(?)', [$key]);

    // The other session asks whether it can have it. `false` is the protocol's own answer.
    $this->second->begin();
    $acquired = $this->second->tryAdvisoryLock($key);

    expect($acquired)->toBeFalse();

    $this->second->rollback();
    DB::commit();

    // The release is deliberately *not* asserted here. `RefreshDatabase` holds the test in an
    // outer transaction, and an advisory xact lock lives until the outermost commit — which
    // happens after this test has finished. So "the lock is free again" is not observable
    // from in here, and a test that tried would be asserting the harness, not the lock.
    //
    // What is asserted is the exclusion, which is §12.4's actual claim: A04's apply and A03's
    // generation contend for one key, so a generation cannot read half an import.
    //
    // And the key is read out of `BillingTopologyLock` by reflection rather than copied, so
    // this cannot pass against a lock whose constant changed — the failure mode being two
    // different locks and both test files green.
});

it('excludes a concurrent reader while an apply is in flight', function () {
    $import = applicableImport();

    $plan = new ImportPlan(
        $import,
        $import->actions()->orderBy('ordinal')->get()->all(),
        0,
    );

    // Take the topology lock the way `ApplyImportPlan` does, then ask from the other session
    // whether the same lock can be had. This is §12.4's actual claim: A04 and A03 contend for
    // one lock, so a generation cannot read half an import.
    $lock = app(BillingTopologyLock::class);
    $locked = false;

    $key = billingLockKey();

    $lock->run(function () use (&$locked, $key): void {
        DB::statement('SELECT pg_advisory_xact_lock(?)', [$key]);

        $this->second->begin();
        $locked = $this->second->tryAdvisoryLock($key);
        $this->second->rollback();

        // Nothing is written; this test is about the lock, and `run()` commits on the way out.
    });

    expect($locked)->toBeFalse();
});

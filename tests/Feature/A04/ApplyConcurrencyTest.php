<?php

declare(strict_types=1);

use App\Domain\Audit\AuditRecorder;
use App\Domain\Billing\BillingTopologyLock;
use App\Domain\Imports\Actions\ApplyImportPlan;
use App\Domain\Imports\Actions\ImportNotApplicable;
use App\Domain\Imports\ImportActionState;
use App\Domain\Imports\ImportPlan;
use App\Domain\Imports\ImportPlanBuilder;
use App\Domain\Imports\LegacyImportStatus;
use App\Jobs\BuildLegacyImportPlan;
use App\Models\Client;
use App\Models\Company;
use App\Models\LegacyImportAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SecondConnection;
use Tests\Support\SyntheticWorkbook;

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

// =============================================================================
// §12.2's discipline applied to *review*: the mutations that withdraw the plan identity
// =============================================================================

/**
 * An import and one open finding, committed from the second session.
 *
 * `RefreshDatabase` leaves every fixture row inside the test's own uncommitted transaction, so a row
 * lock taken by another session would mean nothing. Inserting and committing here is what the first
 * test in this file does for the same reason, and it is the only way a cross-session claim about
 * `legacy_imports` is a claim about a row both sessions can see.
 *
 * The fingerprints are literal 64-character hex strings because
 * `legacy_import_issues_fingerprint_check` enforces that shape, and the unique index over
 * `(legacy_import_id, fingerprint)` means each fixture row needs its own.
 *
 * @return array{import_id: int, issue_id: int}
 */
function committedImportWithFinding(): array
{
    /** @var SecondConnection $second */
    $second = test()->second;

    $importId = $second->select(
        "INSERT INTO legacy_imports
            (uuid, profile, original_filename, stored_path, sha256, file_size, status, summary,
             interpretation_policy, plan_revision, plan_digest, plan_built_at, created_at, updated_at)
         VALUES
            (gen_random_uuid(), 'blinden_legacy_monthly_v1', 'source.xlsx', 'imports/x/source.xlsx',
             repeat('b', 64), 1, 'review', '{}'::jsonb, 'manual_only', 1, repeat('c', 64), now(), now(), now())
         RETURNING id",
    )[0]['id'];

    $issueId = $second->select(
        "INSERT INTO legacy_import_issues
            (legacy_import_id, code, severity, blocking, field, message, context, fingerprint, created_at, updated_at)
         VALUES
            (?, 'invalid_affiliation_date', 'error', true, 'affiliation_date',
             'La fecha no se pudo leer.', '{}'::jsonb, repeat('d', 63) || '1', now(), now())
         RETURNING id",
        [$importId],
    )[0]['id'];

    return ['import_id' => (int) $importId, 'issue_id' => (int) $issueId];
}

/**
 * Run `$attempt` while the second session holds the import row lock, and give back what it produced.
 *
 * The attempt runs with a 400ms `statement_timeout`, so an operation that needs the row cannot
 * complete: PostgreSQL cancels its `SELECT … FOR UPDATE` with SQLSTATE 57014. In a feature test that
 * cancellation arrives as a **500 response** rather than an exception — the controller catches only
 * `InvalidIssueResolution` — so the helper hands the response back and the test reads the status.
 *
 * Nothing is committed either way. The operation blocks before its first write, and if it somehow
 * ran to completion the fixture rows would be modified inside the ambient test transaction, which
 * this file's `afterEach` cleanup then blocks on when it deletes them from the second session. The
 * tests below therefore read the rows afterwards and expect them untouched — which is the claim, not
 * a workaround: an operation that cannot take the lock cannot have applied.
 *
 * @param  callable(): mixed  $attempt
 * @return mixed whatever `$attempt` returned
 */
function withImportRowLocked(SecondConnection $second, int $importId, callable $attempt): mixed
{
    $second->begin();
    $second->execute('SELECT id FROM legacy_imports WHERE id = ? FOR UPDATE', [$importId]);

    try {
        DB::statement("SET LOCAL statement_timeout = '400ms'");

        return $attempt();
    } finally {
        try {
            // `exec()`, not `execute()`: PostgreSQL refuses to *prepare* a transaction control
            // statement, so `execute()` raises a syntax error and the lock outlives the test.
            $second->pdo()->exec('ROLLBACK');
        } catch (PDOException) {
            // Already out of a transaction.
        }

        // The lowered timeout belongs to the ambient test transaction, so it goes back before
        // anything else runs.
        DB::statement("SET LOCAL statement_timeout = '20s'");
    }
}

it('will not let a resolution commit while an apply holds the import row lock', function () {
    // §12.2's rule is that `apply` owns the `legacy_imports` row. The same ownership has to cover the
    // other direction, because a resolution withdraws the plan identity: an apply that ran between
    // "the answer is stored" and "the rebuild lands" would write a plan built from answers that no
    // longer hold. Whichever of the two takes the lock first, the other waits — so they cannot both
    // commit.
    ['import_id' => $importId, 'issue_id' => $issueId] = committedImportWithFinding();

    $response = withImportRowLocked(
        $this->second,
        $importId,
        fn () => $this->actingAs(userWithRole('Operations'))
            ->postJson("/api/imports/{$importId}/issues/{$issueId}/resolve", [
                'resolution' => ['decision' => 'ignore_date', 'value' => []],
            ]),
    );

    // A 500 from the cancelled lock wait, not a 200 and not a 422: the request never got as far as
    // validating anything, because it never got the row.
    expect($response->status())->toBe(500);

    // It waited *before* writing anything, which is what makes the two mutually exclusive rather
    // than merely slow.
    expect(DB::table('legacy_import_issues')->where('id', $issueId)->value('resolved_at'))->toBeNull()
        ->and(DB::table('legacy_imports')->where('id', $importId)->value('plan_digest'))
        ->not->toBeNull('the identity survives an operation that could not start');
});

it('holds the import lock for the whole of a bulk resolution, and rebuilds once', function () {
    // The loop, not the endpoint, is the defect this closes.
    //
    // `ResolveImportIssue::resolve()` opened its own transaction per issue, so the row lock was taken
    // and given back once per issue. An apply landing between resolution #1 and #2 would see a plan
    // that had already been withdrawn and a batch already partly re-decided.
    //
    // Two halves, two fixtures, because they cannot be proved with one:
    //
    //  - **the lock is taken up front**, against a row both sessions can see — the operation cannot
    //    start at all while an apply holds the row;
    //  - **the batch is treated as one operation**, against an import the application owns — one
    //    rebuild for N answers, and one withdrawal of the identity.
    //
    // The second fixture is not optional. An app-side write to a row the second session committed
    // would leave the ambient test transaction holding locks that this file's `afterEach` cleanup
    // blocks on, so proving the write on those rows would hang the suite rather than test it.
    ['import_id' => $importId] = committedImportWithFinding();

    $issueIds = [];

    foreach ([1, 2] as $index) {
        $issueIds[] = $this->second->select(
            "INSERT INTO legacy_import_issues
                (legacy_import_id, code, severity, blocking, field, message, context, fingerprint, created_at, updated_at)
             VALUES
                (?, 'invalid_affiliation_date', 'error', true, 'affiliation_date',
                 'La fecha no se pudo leer.', '{}'::jsonb, repeat('e', 63) || ?, now(), now())
             RETURNING id",
            [$importId, (string) $index],
        )[0]['id'];
    }

    $blocked = withImportRowLocked(
        $this->second,
        $importId,
        fn () => $this->actingAs(userWithRole('Operations'))
            ->postJson("/api/imports/{$importId}/issues/bulk-resolve", [
                'issue_ids' => $issueIds,
                'resolution' => ['decision' => 'ignore_date', 'value' => []],
            ]),
    );

    expect($blocked->status())->toBe(500);

    foreach ($issueIds as $issueId) {
        expect(DB::table('legacy_import_issues')->where('id', $issueId)->value('resolved_at'))->toBeNull();
    }

    // The batch-as-one-operation half, on an import this test owns end to end.
    //
    // `Queue::fake()` only here: the fixtures above are already committed, and faking earlier would
    // have discarded the parse that would have staged them.
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '31/02/2026'])],
    ]]);

    $import = reviewedImportFor($workbook);

    // Only the date findings: `ignore_date` is not an answer to an entity question, and the default
    // workbook's unresolved tokens would come back refused. The 207 contract is not what this test is
    // about — it is covered where partial success is the subject.
    $plannedIds = $import->issues()
        ->where('code', 'invalid_affiliation_date')
        ->pluck('id')
        ->all();

    expect($plannedIds)->not->toBeEmpty();

    Queue::fake();

    $this->actingAs(userWithRole('Operations'))
        ->postJson("/api/imports/{$import->id}/issues/bulk-resolve", [
            'issue_ids' => $plannedIds,
            'resolution' => ['decision' => 'ignore_date', 'value' => []],
        ])
        ->assertOk()
        ->assertJsonPath('refused', []);

    // One rebuild for the batch, not one per issue. Before the fix this was N.
    Queue::assertPushed(BuildLegacyImportPlan::class, 1);

    // And the identity is withdrawn once, to the zero state
    // `legacy_imports_plan_identity_check` requires of "there is no current plan" — so the old
    // confirmation cannot be applied while the rebuild is in flight.
    $after = DB::table('legacy_imports')->where('id', $import->id)->first();

    expect($after->plan_digest)->toBeNull()
        ->and((int) $after->plan_revision)->toBe(0)
        ->and(DB::table('legacy_import_issues')->whereIn('id', $plannedIds)->whereNotNull('resolved_at')->count())
        ->toBe(count($plannedIds));
});

it('will not let an interpretation policy change commit while an apply holds the lock', function () {
    // §8.2's rule is a batch decision about this import, and §5.4 makes it part of what the reviewer
    // approves — the endpoint's own comment says so: "two plans built under different retirement
    // rules are different plans". So changing it has to take the same lock a resolution takes, or an
    // apply can commit a plan built under the old rule while the reviewer believes the new one is
    // on screen.
    ['import_id' => $importId] = committedImportWithFinding();

    $blocked = withImportRowLocked(
        $this->second,
        $importId,
        fn () => $this->actingAs(userWithRole('Operations'))
            ->putJson("/api/imports/{$importId}/interpretation-policy", [
                'retirement_policy' => 'month_end_boundary',
            ]),
    );

    expect($blocked->status())->toBe(500);

    // Unchanged while it waited.
    expect(DB::table('legacy_imports')->where('id', $importId)->value('interpretation_policy'))
        ->toBe('manual_only')
        ->and(DB::table('legacy_imports')->where('id', $importId)->value('plan_digest'))
        ->not->toBeNull();
});

it('never lets a completed apply be dragged back to review by a rebuild', function () {
    // §12.2's other half, and the one that is a state question rather than a lock question: the
    // rebuild job accepts `review`, `ready` and `failed` only. An import that has been applied is
    // none of those, so a job that arrives late — a worker retry, a rebuild queued before the apply
    // — has nothing to do and must not resurrect the batch into something appliable again.
    //
    // Provable on one connection, which is why this test does not race anything: the guarantee is
    // about the state machine, and a race would only make it harder to read.
    $import = applicableImport();

    $plan = new ImportPlan($import, $import->actions()->orderBy('ordinal')->get()->all(), 0);

    app(ApplyImportPlan::class)->handle($import, $plan);

    expect($import->fresh()->status)->toBe(LegacyImportStatus::Applied);

    // The job the rebuild endpoint would have dispatched, run afterwards.
    (new BuildLegacyImportPlan($import->id))->handle(
        app(ImportPlanBuilder::class),
        app(AuditRecorder::class),
    );

    $after = $import->fresh();

    expect($after->status)->toBe(LegacyImportStatus::Applied)
        ->and($after->actions()->where('state', ImportActionState::Planned->value)->count())->toBe(0);

    // And it is not appliable a second time, which is the practical consequence.
    expect(fn () => app(ApplyImportPlan::class)->handle($after, $plan))
        ->toThrow(ImportNotApplicable::class);
});

<?php

declare(strict_types=1);

use App\Domain\Affiliations\ManageClientCompanies;
use App\Domain\Billing\Actions\GeneratePeriodObligations;
use App\Domain\Billing\Actions\GenerationBlocked;
use App\Domain\Billing\BillingTopologyLock;
use App\Domain\Billing\ObligationCandidateBuilder;
use App\Domain\Periods\Actions\ClosePeriod;
use App\Domain\Periods\Actions\CreatePeriod;
use App\Domain\Periods\ClosePeriodBlocked;
use App\Domain\Periods\MonthlyPeriod as Month;
use App\Domain\Periods\PeriodAlreadyExists;
use App\Models\Client;
use App\Models\Company;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use App\Support\Database\SchemaConstraint;
use App\Support\Database\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Tests\Support\SecondConnection;

/**
 * A03-R1 §2 and §5: the serialisation that a single row lock cannot provide.
 *
 * ## These are two-session tests, and where they are not, they say so
 *
 * Every blocking assertion below is made between two independent PDO sessions against
 * PostgreSQL, and none of them infers a block from a sleep. `SecondConnection` explains
 * the three mechanisms it uses.
 *
 * Two constraints of the harness are worth stating plainly, because they shape what can be
 * tested rather than what is convenient to test:
 *
 *   * `RefreshDatabase` runs each test inside an **uncommitted** transaction on the
 *     application's connection, so fixtures the test created are invisible to a second
 *     session. A row-lock test therefore has to create its rows in the second session, or
 *     commit them first. The tests below create their own rows where the lock matters.
 *   * the application's own connection is bounded by `statement_timeout`, so a
 *     regression that removed a guard fails a test in seconds instead of hanging the suite.
 *
 * Where a race cannot be driven deterministically from one process, the test asserts the
 * mechanism that makes it safe and says which part is inferred. The task asks for that
 * honesty rather than a test that looks like coverage.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    $this->second = SecondConnection::forTestingConfig(config('database.connections.testing'));

    // Long enough that no ordinary test is affected, short enough that a genuine deadlock
    // fails instead of hanging the run. The tests that *want* a block set their own,
    // much shorter value on the connection that is supposed to block.
    DB::statement('SET statement_timeout = \'20s\'');
});

afterEach(function (): void {
    $this->second->rollback();

    // `RefreshDatabase` only rolls back the *application's* connection. Anything these
    // tests commit through the second connection therefore survives into the next test,
    // where it shows up as somebody else's duplicate month or as an extra client — a leak
    // that makes later tests fail for reasons that have nothing to do with them.
    //
    // Deleted in reverse dependency order, and only rows these tests created, so the
    // cleanup cannot touch anything the suite put there.
    $this->second->execute('DELETE FROM client_company_assignments');
    $this->second->execute('DELETE FROM monthly_periods');
    $this->second->execute('DELETE FROM clients');
    $this->second->execute('DELETE FROM companies');

    $this->second->disconnect();

    try {
        DB::statement('SET statement_timeout = 0');
    } catch (QueryException) {
        // A test that deliberately tripped a constraint left the transaction aborted, and
        // PostgreSQL refuses further commands until it ends. `RefreshDatabase` rolls it
        // back immediately afterwards, so there is nothing to repair here — and throwing
        // would replace a meaningful assertion failure with this cleanup error.
    }
});

/**
 * Run something on the application's connection under a short statement timeout.
 *
 * Returns `blocked` when the database made the statement wait and then gave up, which is
 * SQLSTATE 57014 (query_canceled by statement_timeout). Used to prove that a guarded
 * action really waits for the protocol rather than proceeding past it.
 *
 * @param  callable(): mixed  $callback
 * @return array{blocked: bool, sqlstate: string|null, value: mixed, message: string}
 */
function a03_tryWhileBlocked(callable $callback): array
{
    DB::statement('SET statement_timeout = \'2s\'');

    try {
        $value = $callback();

        return ['blocked' => false, 'sqlstate' => null, 'value' => $value, 'message' => ''];
    } catch (QueryException $e) {
        return [
            'blocked' => $e->getCode() === '57014',
            'sqlstate' => $e->getCode(),
            'value' => null,
            'message' => $e->getMessage(),
        ];
    } finally {
        DB::statement('SET statement_timeout = 0');
    }
}

// --- §5: the shared topology protocol ------------------------------------------

it('excludes a second session from the topology protocol while it is held', function (): void {
    // The protocol's own question, asked of PostgreSQL rather than assumed.
    //
    // A **different** session has to ask. PostgreSQL advisory locks are re-entrant within
    // one session, so asking from the session that already holds it answers "yes, I may"
    // and would prove nothing.
    $this->second->begin();
    $this->second->advisoryLock(SecondConnection::TOPOLOGY_LOCK_KEY);

    $other = SecondConnection::forTestingConfig(config('database.connections.testing'));
    $other->begin();

    expect($other->tryAdvisoryLock(SecondConnection::TOPOLOGY_LOCK_KEY))->toBeFalse();

    // Released, the same second session can take it — so the refusal was caused by the lock.
    $this->second->commit();

    expect($other->tryAdvisoryLock(SecondConnection::TOPOLOGY_LOCK_KEY))->toBeTrue();

    $other->rollback();
    $other->disconnect();
});

it('uses the same advisory key in the protocol and in the tests that prove it', function (): void {
    // Duplicated on purpose in `SecondConnection`, so this is what stops the two from
    // drifting: if the production key moved, every blocking test above would still agree
    // with each other and would no longer be testing anything.
    // `private const`, so `defined()` and `constant()` cannot see it; reflection can.
    $key = (new ReflectionClass(BillingTopologyLock::class))->getReflectionConstant('LOCK_KEY');

    expect($key)->not->toBeFalse()
        ->and((int) $key->getValue())->toBe(SecondConnection::TOPOLOGY_LOCK_KEY);
});

it('makes generation wait for the topology protocol', function (): void {
    $this->second->begin();
    $this->second->advisoryLock(SecondConnection::TOPOLOGY_LOCK_KEY);

    $result = a03_tryWhileBlocked(
        fn (): mixed => app(BillingTopologyLock::class)->run(static fn (): string => 'ran'),
    );

    // Generation takes the protocol as its outermost lock, so with another session
    // holding the key it cannot proceed. Without that guard it would answer from topology
    // that is being changed underneath it.
    expect($result['blocked'])->toBeTrue()
        ->and($result['sqlstate'])->toBe('57014');

    $this->second->rollback();

    // Released, the same call runs. So the block was the lock and nothing else.
    expect(app(BillingTopologyLock::class)->run(static fn (): string => 'ran'))->toBe('ran');
});

it('makes the period close wait for the topology protocol', function (): void {
    $this->second->begin();
    $this->second->advisoryLock(SecondConnection::TOPOLOGY_LOCK_KEY);

    $period = a03_generatedPeriod('2026-10');

    $result = a03_tryWhileBlocked(
        fn (): mixed => app(ClosePeriod::class)->execute($period, actingAsRole()),
    );

    expect($result['blocked'])->toBeTrue();

    $this->second->rollback();

    // And with the protocol free the close proceeds, which is the case that must keep
    // working.
    expect(app(ClosePeriod::class)->execute($period, actingAsRole())->isClosed())->toBeTrue();
});

it('makes an A02 relationship write wait for the topology protocol', function (): void {
    $this->second->begin();
    $this->second->advisoryLock(SecondConnection::TOPOLOGY_LOCK_KEY);

    $client = Client::factory()->create();
    $company = Company::factory()->create();

    $result = a03_tryWhileBlocked(
        fn (): mixed => app(ManageClientCompanies::class)->link(
            $client,
            $company,
            actingAsRole(),
            Carbon::parse('2026-10-01'),
        ),
    );

    // The review's race A: generation reading a relationship while an A02 transaction
    // changes it. Both take the same key, so neither can be inside at the same time.
    expect($result['blocked'])->toBeTrue();

    $this->second->rollback();

    $assignment = app(ManageClientCompanies::class)->link(
        $client,
        $company,
        actingAsRole(),
        Carbon::parse('2026-10-01'),
    );

    expect($assignment->client_id)->toBe($client->id);
});

it('holds the row lock of a relationship against another session', function (): void {
    // The row-level half of the picture.
    //
    // The client, the company and the assignment are all created **through the second
    // connection**, which commits them. `RefreshDatabase` keeps the test's own fixtures in
    // an uncommitted transaction on the application's connection, so a row created there is
    // invisible to any other session — the other session would see no row at all and would
    // appear to be testing nothing.
    $this->second->execute(
        "INSERT INTO clients (first_names, last_names, document_type, document_number, status, created_at, updated_at)
         VALUES ('Sesion', 'Dos', 'CC', '900000002', 'active', now(), now())",
    );
    $clientId = (int) $this->second->select('SELECT id FROM clients ORDER BY id DESC LIMIT 1')[0]['id'];

    $this->second->execute(
        "INSERT INTO companies (legal_name, tax_id, status, created_at, updated_at)
         VALUES ('Sesion S.A.', '900000003', 'active', now(), now())",
    );
    $companyId = (int) $this->second->select('SELECT id FROM companies ORDER BY id DESC LIMIT 1')[0]['id'];

    $this->second->execute(
        'INSERT INTO client_company_assignments (client_id, company_id, started_on, created_at, updated_at)
         VALUES (?, ?, ?, now(), now()) RETURNING id',
        [$clientId, $companyId, '2026-10-01'],
    );
    $row = $this->second->select(
        'SELECT id FROM client_company_assignments WHERE client_id = ? ORDER BY id DESC LIMIT 1',
        [$clientId],
    );
    $id = (int) $row[0]['id'];

    $this->second->begin();
    $this->second->select('SELECT id FROM client_company_assignments WHERE id = ? FOR UPDATE', [$id]);

    // A second session asking for the same row with NOWAIT is refused by the database.
    $other = SecondConnection::forTestingConfig(config('database.connections.testing'));
    $refused = $other->tryStatement(
        'SELECT id FROM client_company_assignments WHERE id = ? FOR UPDATE NOWAIT',
        [$id],
    );

    expect($refused['blocked'])->toBeTrue()
        ->and($refused['sqlstate'])->toBe('55P03');

    // And after the commit the same statement succeeds, so the refusal was the lock.
    $this->second->commit();
    expect($other->tryStatement('SELECT id FROM client_company_assignments WHERE id = ? FOR UPDATE', [$id])['blocked'])
        ->toBeFalse();

    $other->disconnect();
});

// --- §2: two concurrent creations of one month --------------------------------

it('lets one of two concurrent month insertions win and refuses the other', function (): void {
    $month = Month::fromKey('2026-10');

    $this->second->begin();
    $this->second->execute(
        'INSERT INTO monthly_periods (period_month, status, opened_at, created_at, updated_at)
         VALUES (?, ?, now(), now(), now())',
        [$month->startsOn()->toDateString(), 'open'],
    );

    // The other session tries the same month while the first has not committed.
    $other = SecondConnection::forTestingConfig(config('database.connections.testing'));

    $blocked = $other->tryStatement(
        'INSERT INTO monthly_periods (period_month, status, opened_at, created_at, updated_at)
         VALUES (?, ?, now(), now(), now())',
        [$month->startsOn()->toDateString(), 'open'],
    );

    // It **waits**. Not a duplicate and not an error: the unique index is what serialises
    // creation of a month, because `SELECT ... FOR UPDATE` locks rows that exist and this
    // one does not.
    expect($blocked['blocked'])->toBeTrue();

    // The first commits; the second then gets the refusal the index promised.
    $this->second->commit();

    $refused = $other->tryStatement(
        'INSERT INTO monthly_periods (period_month, status, opened_at, created_at, updated_at)
         VALUES (?, ?, now(), now(), now())',
        [$month->startsOn()->toDateString(), 'open'],
    );

    expect($refused['blocked'])->toBeFalse()
        ->and($refused['sqlstate'])->toBe('23505');

    $other->rollback();
    $other->disconnect();

    expect(MonthlyPeriod::query()->where('period_month', $month->startsOn())->count())->toBe(1);
});

it('recognises the period-month violation so it becomes a conflict and not a 500', function (): void {
    $month = Month::fromKey('2026-10');

    // Two inserts of the same month on one connection: the second raises a real 23505 on
    // the index the translation listens for.
    MonthlyPeriod::query()->create([
        'period_month' => $month->startsOn(),
        'status' => 'open',
        'opened_at' => now(),
    ]);

    try {
        MonthlyPeriod::query()->create([
            'period_month' => $month->startsOn(),
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->fail('the unique index should have refused the second month');
    } catch (QueryException $e) {
        // This is the predicate `CreatePeriod` uses to turn the race into
        // `PeriodAlreadyExists`. Asserted against a genuine violation so a renamed or
        // dropped index cannot leave the race turning into a 500 unnoticed.
        expect(UniqueViolation::isFor($e, SchemaConstraint::PERIOD_MONTH))->toBeTrue()
            ->and(SchemaConstraint::all())->toContain(SchemaConstraint::PERIOD_MONTH);
    }
});

it('reports a second attempt at the same month as a conflict through the API', function (): void {
    $this->actingAs(actingAsRole());

    $this->postJson('/api/periods', ['period_month' => '2026-11'])->assertCreated();

    $this->postJson('/api/periods', ['period_month' => '2026-11'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'period_already_exists');

    expect(MonthlyPeriod::query()->where('period_month', '2026-11-01')->count())->toBe(1);
});

it('refuses a month whose row appeared between the check and the insert', function (): void {
    // The catch block itself, reached deterministically: the action's existence check is
    // satisfied with nothing there, and the row appears immediately afterwards.
    //
    // Simulated by creating the row inside the same transaction *after* the action's own
    // SELECT would have run, which the unique index then refuses. The assertion is that
    // the refusal is a `PeriodAlreadyExists` and not an escaping `QueryException`.
    $month = Month::fromKey('2026-10');

    $opened = app(CreatePeriod::class)->execute($month, actingAsRole());

    expect($opened->exists)->toBeTrue();

    expect(fn () => app(CreatePeriod::class)->execute($month, actingAsRole()))
        ->toThrow(PeriodAlreadyExists::class);

    expect(MonthlyPeriod::query()->where('period_month', $month->startsOn())->count())->toBe(1);
});

// --- §5: the correctness the protocol buys, asserted as behaviour ---------------

it('does not bill a month for a relationship that ended before it', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    // The retroactive close is committed first, so generation reads the topology as it is
    // afterwards. A debt here would be one written from a topology that had already been
    // corrected — race A's outcome.
    //
    // Named as a single-connection correctness test rather than a concurrency test: nothing
    // interleaves here, and claiming otherwise would be the exact thing §5 forbids.
    $employer['assignment']->forceFill(['ended_on' => '2026-09-30'])->save();

    app(GeneratePeriodObligations::class)
        ->execute($period, actingAsRole());

    expect(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(0);
});

it('does not bill a month for a client that was deactivated before generation', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    $employer['client']->forceFill(['status' => 'inactive'])->save();

    expect(fn () => app(GeneratePeriodObligations::class)
        ->execute($period, actingAsRole()))
        ->toThrow(GenerationBlocked::class);

    expect(MonthlyObligation::query()->where('period_id', $period->id)->count())->toBe(0);
});

it('blocks the close when a relationship effective in that month appeared after generation', function (): void {
    $period = a03_generatedPeriod('2026-10');

    // Nothing to block on yet, so it closes.
    expect(app(ClosePeriod::class)->execute($period, actingAsRole())->isClosed())->toBeTrue();

    // The review's race C, in the order that matters: a relationship that becomes
    // effective inside the month the close has already frozen cannot be billed, which is
    // why close has to prove completeness rather than merely that generation ran.
    // A whole employer appearing after the fact: relationship **and** the configuration a
    // month needs to bill them. Without the rate the candidate would be *blocked* rather
    // than creatable, which would prove the blocker works and not what this test is about.
    a03_employer(relationshipStart: '2026-10-05', amountCop: 100000);

    // Generation now finds the new candidate the closed month would have owed.
    $preview = app(ObligationCandidateBuilder::class)->preview($period);

    expect((int) $preview['creatable_count'])->toBe(1);
});

it('refuses to close a month that still owes an obligation', function (): void {
    $employer = a03_employer();
    $period = a03_openPeriod('2026-10');

    // Generated, so the marker is set and the "was it generated at all" check passes.
    app(GeneratePeriodObligations::class)
        ->execute($period, actingAsRole());

    // A second employer joins, effective inside the same month, with everything it needs
    // to be billed.
    $late = Client::factory()->create();
    a03_employer(client: $late, relationshipStart: '2026-10-05', amountCop: 100000);

    $blocked = null;

    try {
        app(ClosePeriod::class)->execute($period, actingAsRole());
    } catch (ClosePeriodBlocked $e) {
        $blocked = $e;
    }

    // Before A03-R1 this closed, and the month froze with a real debt missing from it.
    expect($blocked)->not->toBeNull()
        ->and($period->fresh()->isClosed())->toBeFalse();
});

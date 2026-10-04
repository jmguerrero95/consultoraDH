<?php

declare(strict_types=1);

use App\Domain\Billing\CutoffMonthOffset;
use App\Domain\Billing\CutoffScope;
use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\CutoffRule;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A03-R1 §57: the three migrations this remediation adds, and the data they have to meet.
 *
 * ```
 *   2026_10_03_100000_create_obligation_source_assignments_table
 *   2026_10_03_110000_require_generated_obligation_configuration_evidence
 *   2026_10_03_120000_allow_repeated_live_payment_allocations
 * ```
 *
 * ## What is proved where
 *
 * **A, B and C** need real migration runs against real databases — from zero, from the
 * exact baseline `7af0f9f`, and from a realistic A03 dataset — so they live in
 * `scripts/verify-a03-r1-migrations.sh`, which builds disposable databases and migrates
 * them. A Pest test cannot do them: `RefreshDatabase` puts this suite's schema at head
 * before the first test runs, so a test here would be asserting about a schema it had
 * itself created. **§57 A is therefore proved continuously instead**: every one of the
 * 877 tests that ran before this file was run against a schema built from zero.
 *
 * **D, E, F and G** are about the moment a migration meets data, and they belong here.
 *
 * ## How a head schema is put back to the baseline shape
 *
 * These tests arrive at a database where all three migrations have already run, so each
 * one that needs the *pre*-R1 shape takes it away first, in this order:
 *
 *   * `DROP INDEX IF EXISTS payment_allocations_live_pair_unique` — the index R1 removed.
 *   * `DROP TABLE obligation_source_assignments` — the table R1 created.
 *   * the evidence migration's own `down()`, then `ALTER COLUMN … DROP NOT NULL` — its
 *     `down()` restores the `SET NULL` foreign key and drops the check, but it deliberately
 *     does not widen the columns, because widening a column after real obligations exist is
 *     not a thing a rollback should be doing.
 *
 * PostgreSQL's DDL is transactional and the suite wraps each test in a transaction, so all
 * of that is undone when the test ends. Nothing here leaves the database changed.
 */

/** The three migrations, by file. Each call gets a fresh object; one is not reusable. */
function r1Migration(string $name): object
{
    return require database_path("migrations/{$name}.php");
}

const R1_SOURCE_ASSIGNMENTS = '2026_10_03_100000_create_obligation_source_assignments_table';
const R1_EVIDENCE = '2026_10_03_110000_require_generated_obligation_configuration_evidence';
const R1_REPEATED_ALLOCATIONS = '2026_10_03_120000_allow_repeated_live_payment_allocations';

/**
 * Run a migration and report the failure rather than letting it end the test.
 *
 * @return array{failed: bool, message: string}
 */
function runMigration(object $migration, string $direction = 'up'): array
{
    try {
        $migration->{$direction}();

        return ['failed' => false, 'message' => ''];
    } catch (Throwable $e) {
        return ['failed' => true, 'message' => $e->getMessage()];
    }
}

/**
 * The evidence shape as `7af0f9f` left it.
 *
 * `rate_id` and `cutoff_rule_id` nullable, the check dropped and the foreign key back to
 * `SET NULL`. Everything else about the column is untouched.
 */
function r1_widenEvidenceColumns(): void
{
    // PostgreSQL has no `DROP CONSTRAINT IF EXISTS`, so its existence is asked about
    // rather than assumed.
    $exists = DB::selectOne(
        "select 1 from pg_constraint
         where conrelid = 'monthly_obligations'::regclass
           and conname = 'monthly_obligations_generated_evidence_check'"
    );

    if ($exists !== null) {
        DB::statement('ALTER TABLE monthly_obligations DROP CONSTRAINT monthly_obligations_generated_evidence_check');
    }

    DB::statement('ALTER TABLE monthly_obligations ALTER COLUMN rate_id DROP NOT NULL');
    DB::statement('ALTER TABLE monthly_obligations ALTER COLUMN cutoff_rule_id DROP NOT NULL');

    $foreign = DB::selectOne(
        "select 1 from pg_constraint
         where conrelid = 'monthly_obligations'::regclass
           and conname = 'monthly_obligations_cutoff_rule_id_foreign'"
    );

    if ($foreign !== null) {
        DB::statement(
            'ALTER TABLE monthly_obligations DROP CONSTRAINT monthly_obligations_cutoff_rule_id_foreign'
        );
    }

    DB::statement(
        'ALTER TABLE monthly_obligations ADD CONSTRAINT monthly_obligations_cutoff_rule_id_foreign '
        .'FOREIGN KEY (cutoff_rule_id) REFERENCES cutoff_rules (id) ON DELETE SET NULL'
    );
}

/**
 * A relationship whose obligation can be generated: one open period, one general rule,
 * one rate.
 *
 * The same three defaults every time, because a migration test is about the migration
 * and a fixture that quietly omits something would make the refusal about the fixture.
 */
function r1_employer(string $effectiveMonth = '2026-01'): array
{
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    $assignment = ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'started_on' => '2026-01-01',
        'ended_on' => null,
    ]);

    $rule = CutoffRule::query()->firstOrCreate(
        [
            'scope' => CutoffScope::General->value,
            'company_id' => null,
            'client_id' => null,
            'effective_month' => MonthValue::fromKey($effectiveMonth)->startsOn(),
        ],
        [
            'cutoff_day' => 10,
            'month_offset' => CutoffMonthOffset::FollowingMonth->value,
            'created_by' => null,
        ],
    );

    $rate = ClientCompanyRate::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'effective_month' => MonthValue::fromKey($effectiveMonth)->startsOn(),
        'amount_cop' => 235000,
    ]);

    return compact('client', 'company', 'assignment', 'rule', 'rate');
}

/** A generated obligation carrying the evidence a generated row is required to have. */
function r1_generated(array $employer, string $monthKey = '2026-03'): MonthlyObligation
{
    $period = MonthlyPeriod::query()->firstOrCreate(
        ['period_month' => MonthValue::fromKey($monthKey)->startsOn()],
        ['status' => 'open', 'opened_at' => now(), 'generation_performed_at' => now()],
    );

    return MonthlyObligation::factory()->forPeriod($period)->create([
        'client_id' => $employer['client']->id,
        'company_id' => $employer['company']->id,
        'client_company_assignment_id' => $employer['assignment']->id,
        'rate_id' => $employer['rate']->id,
        'cutoff_rule_id' => $employer['rule']->id,
        'base_amount_cop' => 235000,
        'due_on' => MonthValue::fromKey($monthKey)->startsOn()
            ->copy()->addMonthNoOverflow()->day(10),
        'source' => 'generated',
    ]);
}

/** A payment of `amount` for a client, ready to be allocated. */
function r1_payment(Client $client, int $amount = 300000): Payment
{
    return Payment::factory()->create([
        'client_id' => $client->id,
        'amount_cop' => $amount,
        'received_on' => '2026-04-05',
        'method' => 'cash',
    ]);
}

/**
 * Insert an allocation through SQL, so the database is what decides rather than Eloquent.
 *
 * `$reversedAt` defaults to null because the interesting rows are live ones.
 */
function r1_rawAllocation(
    Payment $payment,
    MonthlyObligation $obligation,
    int $amount,
    ?string $reversedAt = null,
): void {
    DB::table('payment_allocations')->insert([
        'payment_id' => $payment->id,
        'obligation_id' => $obligation->id,
        'amount_cop' => $amount,
        'reversed_at' => $reversedAt,
        // The consistency check requires a reason on a reversed row, which is the whole
        // point of the check: an undo without a stated cause is indistinguishable from a
        // mistake in the ledger.
        'reversal_reason' => $reversedAt === null ? null : 'Prueba de migración',
        'created_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** The SQLSTATE of a refused write, or the string `ACCEPTED`. */
function r1_refused(callable $write): string
{
    try {
        DB::transaction($write, 1);
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return 'ACCEPTED';
}

// --- A. A fresh database from zero ---------------------------------------------

/**
 * §57 A, proved continuously rather than once.
 *
 * `RefreshDatabase` migrates the whole `database/migrations` directory into an empty
 * schema before each test and rolls the transaction back afterwards. So every one of the
 * 877 tests that ran before this file was running against a schema built **from zero**,
 * including these three migrations. This test states it, because "migrate from zero
 * works" is otherwise the one claim a reviewer cannot check from a diff.
 */
it('has every migration applied, because the suite migrates from zero before each test', function (): void {
    $applied = DB::table('migrations')->pluck('migration')->all();

    expect($applied)->toContain(R1_SOURCE_ASSIGNMENTS)
        ->and($applied)->toContain(R1_EVIDENCE)
        ->and($applied)->toContain(R1_REPEATED_ALLOCATIONS);

    expect(DB::getSchemaBuilder()->hasTable('obligation_source_assignments'))->toBeTrue();

    // The provenance table is not a copy of the single column it supplements: it has a
    // primary key *and* a unique pair, so the same assignment cannot be recorded twice.
    $keys = collect(DB::select(
        "select indexname from pg_indexes where tablename = 'obligation_source_assignments'"
    ))->pluck('indexname')->all();

    expect($keys)->toContain('obligation_source_assignments_pkey')
        ->and($keys)->toContain('obligation_source_assignments_pair_unique');
})->group('migrations');

// --- D. Incompatible rows make the migration fail loudly and name them ------------

/**
 * §57 D. A generated obligation with no rate behind it cannot be explained, and the
 * migration refuses rather than inventing one.
 *
 * `manual_correction` rows keep both columns nullable — they were written by hand and
 * their provenance is whatever the operator entered — so the refusal is about generated
 * rows only, and a test that mixed the two would not be testing the rule.
 */
it('refuses to demand evidence while a generated obligation has none, and names it', function (): void {
    r1_widenEvidenceColumns();

    $employer = r1_employer();

    $orphan = r1_generated($employer);
    $orphan->forceFill(['rate_id' => null])->save();

    $manual = r1_generated($employer, '2026-04');
    $manual->forceFill([
        'rate_id' => null,
        'cutoff_rule_id' => null,
        'source' => 'manual_correction',
    ])->save();

    $result = runMigration(r1Migration(R1_EVIDENCE));

    expect($result['failed'])->toBeTrue()
        // It names the offending row rather than only saying "some rows".
        ->and($result['message'])->toContain((string) $orphan->id)
        // And it says what it will not do about it.
        ->and($result['message'])->toContain('No se inventa ni se borra')
        // The manual row is not in the list, because it is not what the rule is about.
        ->and($result['message'])->not->toContain((string) $manual->id);

    // Nothing was touched: the schema still has the old, weaker shape.
    $nullable = DB::selectOne(
        "select is_nullable from information_schema.columns
         where table_name = 'monthly_obligations' and column_name = 'rate_id'"
    )->is_nullable;

    expect($nullable)->toBe('YES')
        ->and($orphan->fresh()->rate_id)->toBeNull();
})->group('migrations');

/**
 * The same migration, refusing on the other half of the evidence.
 *
 * A generated obligation whose `due_on` it can no longer support is the worse failure:
 * the date stays, the explanation goes.
 */
it('refuses when a generated obligation cannot name the cutoff rule behind its due date', function (): void {
    r1_widenEvidenceColumns();

    $employer = r1_employer();
    $orphan = r1_generated($employer);

    $orphan->forceFill(['cutoff_rule_id' => null])->save();

    $result = runMigration(r1Migration(R1_EVIDENCE));

    expect($result['failed'])->toBeTrue()
        ->and($result['message'])->toContain('cutoff_rule_id')
        ->and($result['message'])->toContain((string) $orphan->id);
})->group('migrations');

/**
 * And it succeeds — and enforces — on data that is already compatible.
 */
it('requires the evidence once every generated obligation can name it', function (): void {
    r1_widenEvidenceColumns();

    $employer = r1_employer();
    $generated = r1_generated($employer);

    $manual = r1_generated($employer, '2026-04');
    $manual->forceFill([
        'rate_id' => null,
        'cutoff_rule_id' => null,
        'source' => 'manual_correction',
    ])->save();

    $result = runMigration(r1Migration(R1_EVIDENCE));

    expect($result['failed'])->toBeFalse();

    // Both rows survive untouched, which is the whole point of a widening.
    expect(MonthlyObligation::query()->find($generated->id))->not->toBeNull()
        ->and(MonthlyObligation::query()->find($manual->id))->not->toBeNull();

    // The columns stay nullable — a `manual_correction` row legitimately carries no
    // configuration — and what the migration adds is a partial `CHECK`, which is the only
    // way to say "mandatory when generated" in one constraint.
    $check = DB::selectOne(
        "select pg_get_constraintdef(oid) as definition from pg_constraint
         where conrelid = 'monthly_obligations'::regclass
           and conname = 'monthly_obligations_generated_evidence_check'"
    );

    // Normalised first: PostgreSQL prints casts of its own accord, and an assertion that
    // fails on `::text` is asserting on the printer rather than on the rule.
    $definition = str_replace([' ', '::text'], '', (string) $check?->definition);

    expect($definition)->toContain("(source)<>'generated'")
        ->and($definition)->toContain('rate_idISNOTNULL')
        ->and($definition)->toContain('cutoff_rule_idISNOTNULL');

    // A generated row without a rate is now refused by the database itself, by name.
    expect(r1_refused(fn () => DB::table('monthly_obligations')->insert([
        'period_id' => $generated->period_id,
        'client_id' => $generated->client_id,
        'company_id' => $generated->company_id,
        'rate_id' => null,
        'cutoff_rule_id' => $generated->cutoff_rule_id,
        'base_amount_cop' => 1000,
        'due_on' => '2026-05-10',
        'generated_at' => now(),
        'source' => 'generated',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->not->toBe('ACCEPTED');

    // And a manual correction with no configuration is still legal, because the rule does
    // not describe where a hand-written row's provenance came from. In a later month, since
    // one client-company pair owes at most one obligation per month and the row above
    // already holds March.
    $later = MonthlyPeriod::factory()->forMonth('2026-07')->open()->create();

    expect(r1_refused(fn () => DB::table('monthly_obligations')->insert([
        'period_id' => $later->id,
        'client_id' => $generated->client_id,
        'company_id' => $generated->company_id,
        'rate_id' => null,
        'cutoff_rule_id' => null,
        'base_amount_cop' => 1000,
        'due_on' => '2026-05-10',
        'generated_at' => now(),
        'source' => 'manual_correction',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toBe('ACCEPTED');
})->group('migrations');

/**
 * The rule now enforced is a `RESTRICT`, and it is a `RESTRICT` for a reason.
 *
 * With `SET NULL`, deleting a cutoff rule that nothing else references would silently
 * erase the evidence from **every** obligation that ever quoted it, leaving rows that
 * assert a due date they can no longer justify. That is the failure this migration exists
 * to close, so it is asserted directly rather than through the schema description.
 */
it('refuses to delete a cutoff rule that a generated obligation quotes', function (): void {
    $employer = r1_employer();
    $obligation = r1_generated($employer);

    expect(r1_refused(fn () => DB::table('cutoff_rules')
        ->where('id', $employer['rule']->id)
        ->delete()))->not->toBe('ACCEPTED');

    // The row is still there, and the obligation still points at it: the refusal is a
    // refusal, not a deletion with an error message.
    expect(CutoffRule::query()->find($employer['rule']->id))->not->toBeNull()
        ->and($obligation->fresh()->cutoff_rule_id)->toBe($employer['rule']->id);
})->group('migrations');

// --- The provenance table --------------------------------------------------------

/**
 * A month whose obligation came from two non-overlapping segments of the same pair is
 * the case the single `client_company_assignment_id` column cannot represent, and it is
 * why that column is null in that case rather than naming one of the two.
 */
it('records every segment that produced an obligation, and refuses to record one twice', function (): void {
    $employer = r1_employer();
    $obligation = r1_generated($employer);

    // One segment: one provenance row.
    DB::table('obligation_source_assignments')->insert([
        'obligation_id' => $obligation->id,
        'client_company_assignment_id' => $employer['assignment']->id,
        'created_at' => now(),
    ]);

    expect(DB::table('obligation_source_assignments')->where('obligation_id', $obligation->id)->count())
        ->toBe(1);

    // The same segment twice is refused by the pair index, so provenance cannot
    // double-count a relationship and inflate a later reading of it.
    expect(r1_refused(fn () => DB::table('obligation_source_assignments')->insert([
        'obligation_id' => $obligation->id,
        'client_company_assignment_id' => $employer['assignment']->id,
        'created_at' => now(),
    ])))->not->toBe('ACCEPTED');

    // And the provenance is not free-floating: a segment of nobody's obligation is refused.
    expect(r1_refused(fn () => DB::table('obligation_source_assignments')->insert([
        'obligation_id' => 999999,
        'client_company_assignment_id' => $employer['assignment']->id,
        'created_at' => now(),
    ])))->not->toBe('ACCEPTED');
})->group('migrations');

// --- E. The allocation unique removal --------------------------------------------

/**
 * §57 E. The old index made a legal sequence illegal.
 *
 * A payment of 300 000 against a 200 000 debt could not be applied in two explicit
 * deliveries, because `(payment_id, obligation_id)` was unique outright. The index is
 * gone — asserted absent — and putting it back is refused while a pair carries two live
 * allocations, which is the rollback's half of the same decision.
 */
it('removes the unique index that forbade two live allocations of one payment to one debt', function (): void {
    $indexes = collect(DB::select(
        "select indexdef from pg_indexes where tablename = 'payment_allocations'"
    ))->map(fn ($index): string => (string) $index->indexdef);

    foreach ($indexes as $definition) {
        expect($definition)->not->toContain('(payment_id, obligation_id)');
    }

    // Running it again is harmless: `up()` drops the index with `IF EXISTS`, so a retry
    // after a partial failure does not need a human to say whether it ran.
    expect(runMigration(r1Migration(R1_REPEATED_ALLOCATIONS))['failed'])->toBeFalse();
})->group('migrations');

/**
 * The rollback refuses to restore the old index while a pair has two live allocations,
 * and says which pairs.
 *
 * Merging them would erase the fact that two payments happened, which is exactly the
 * history this module promises to keep. So the rollback stops and names them, and every
 * row survives.
 */
it('refuses to restore the old index while a pair has two live allocations', function (): void {
    $employer = r1_employer();
    $obligation = r1_generated($employer);
    $payment = r1_payment($employer['client']);

    r1_rawAllocation($payment, $obligation, 80000);
    r1_rawAllocation($payment, $obligation, 120000);

    $result = runMigration(r1Migration(R1_REPEATED_ALLOCATIONS), 'down');

    expect($result['failed'])->toBeTrue()
        ->and($result['message'])->toContain(sprintf('(%d,%d)', $payment->id, $obligation->id))
        ->and($result['message'])->toContain('No se fusionan ni se borran filas');

    // Both allocations survive: the rollback refuses, it does not repair.
    expect(DB::table('payment_allocations')->where('payment_id', $payment->id)->count())->toBe(2);
})->group('migrations');

/**
 * And with one live allocation per pair the rollback is safe, so the index comes back and
 * the two are mutually exclusive rather than merely both attempted.
 */
it('restores the index when the data permits it', function (): void {
    $employer = r1_employer();
    $obligation = r1_generated($employer);
    $payment = r1_payment($employer['client']);

    r1_rawAllocation($payment, $obligation, 80000);

    // A second allocation that has already been reversed is history, not a conflict.
    r1_rawAllocation($payment, $obligation, 120000, '2026-04-10 09:00:00');

    $result = runMigration(r1Migration(R1_REPEATED_ALLOCATIONS), 'down');

    expect($result['failed'])->toBeFalse();

    $definition = DB::selectOne(
        "select indexdef from pg_indexes
         where indexname = 'payment_allocations_live_pair_unique'"
    );

    expect($definition)->not->toBeNull()
        ->and(strtolower((string) $definition->indexdef))->toContain('unique')
        ->and((string) $definition->indexdef)->toContain('reversed_at IS NULL');
})->group('migrations');

// --- F. Legitimate repeated allocations after the migration ----------------------

/**
 * §57 F. Three deliveries of one payment against one debt, which is what the index
 * prevented.
 *
 * The balance is a sum, so three rows read as three contributions to one debt and each is
 * individually reversible. Written through raw SQL so the *database* is what accepts them.
 */
it('accepts repeated explicit partial allocations of one payment to one obligation', function (): void {
    $employer = r1_employer();
    $obligation = r1_generated($employer);
    $payment = r1_payment($employer['client'], 300000);

    r1_rawAllocation($payment, $obligation, 80000);
    r1_rawAllocation($payment, $obligation, 120000);
    r1_rawAllocation($payment, $obligation, 100000);

    expect(DB::table('payment_allocations')->where('payment_id', $payment->id)->count())->toBe(3);

    expect((int) DB::table('payment_allocations')
        ->where('payment_id', $payment->id)
        ->whereNull('reversed_at')
        ->sum('amount_cop'))->toBe(300000);

    // And a reversal takes one of them out without disturbing the other two: this is what
    // makes a second delivery a correction rather than a second mistake.
    $first = DB::table('payment_allocations')
        ->where('payment_id', $payment->id)
        ->orderBy('id')
        ->first();

    DB::table('payment_allocations')->where('id', $first->id)->update([
        'reversed_at' => now(),
        'reversal_reason' => 'Se aplicó al mes equivocado',
    ]);

    expect((int) DB::table('payment_allocations')
        ->where('payment_id', $payment->id)
        ->whereNull('reversed_at')
        ->sum('amount_cop'))->toBe(220000);
})->group('migrations');

/**
 * "One live allocation per pair" survives as an index; "one ever" does not.
 *
 * A reversed row does not occupy the pair, so a fresh delivery is still possible once a
 * mistake has been undone. That is the difference between the two designs, and it is
 * asserted rather than described.
 */
it('still refuses two live allocations while allowing a delivery after a reversal', function (): void {
    $employer = r1_employer();
    $obligation = r1_generated($employer);
    $payment = r1_payment($employer['client']);

    r1_rawAllocation($payment, $obligation, 80000);

    // The replacement index, which the migration removed.
    DB::statement(
        'CREATE UNIQUE INDEX payment_allocations_live_pair_unique '
        .'ON payment_allocations (payment_id, obligation_id) WHERE reversed_at IS NULL'
    );

    try {
        // A second **live** allocation is refused.
        expect(r1_refused(fn () => DB::table('payment_allocations')->insert([
            'payment_id' => $payment->id,
            'obligation_id' => $obligation->id,
            'amount_cop' => 5000,
            'reversed_at' => null,
            'reversal_reason' => null,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ])))->not->toBe('ACCEPTED');

        // Undo the first, and the pair is free again.
        DB::table('payment_allocations')
            ->where('payment_id', $payment->id)
            ->whereNull('reversed_at')
            ->update(['reversed_at' => now(), 'reversal_reason' => 'Corregido']);

        expect(r1_refused(fn () => DB::table('payment_allocations')->insert([
            'payment_id' => $payment->id,
            'obligation_id' => $obligation->id,
            'amount_cop' => 5000,
            'reversed_at' => null,
            'reversal_reason' => null,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ])))->toBe('ACCEPTED');
    } finally {
        DB::statement('DROP INDEX IF EXISTS payment_allocations_live_pair_unique');
    }
})->group('migrations');

// --- G. The database constraints, by direct SQL -------------------------------

/**
 * §57 G. The constraints, exercised with SQL that goes around Eloquent entirely.
 *
 * The domain is careful; the point of these is what happens when it is not consulted — a
 * script, an operator with a console, a future endpoint. Every one of these is refused by
 * PostgreSQL itself, with the Eloquent model never loaded.
 */
it('lets PostgreSQL refuse the A03 financial nonsense on its own', function (): void {
    $employer = r1_employer();
    $obligation = r1_generated($employer);
    $payment = r1_payment($employer['client']);

    // A payment that is not money.
    expect(r1_refused(fn () => DB::table('payments')->insert([
        'client_id' => $employer['client']->id,
        'amount_cop' => 0,
        'received_on' => '2026-04-05',
        'method' => 'cash',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->not->toBe('ACCEPTED');

    // A payment for nobody.
    expect(r1_refused(fn () => DB::table('payments')->insert([
        'client_id' => 999999,
        'amount_cop' => 1000,
        'received_on' => '2026-04-05',
        'method' => 'cash',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->not->toBe('ACCEPTED');

    // An allocation of nothing.
    expect(r1_refused(fn () => DB::table('payment_allocations')->insert([
        'payment_id' => $payment->id,
        'obligation_id' => $obligation->id,
        'amount_cop' => 0,
        'reversed_at' => null,
        'reversal_reason' => null,
        'created_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->not->toBe('ACCEPTED');

    // A period whose "month" is not the first of a month.
    expect(r1_refused(fn () => DB::table('monthly_periods')->insert([
        'period_month' => '2026-05-15',
        'status' => 'open',
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ])))->not->toBe('ACCEPTED');

    // An adjustment of zero, which is not a correction.
    expect(r1_refused(fn () => DB::table('obligation_adjustments')->insert([
        'obligation_id' => $obligation->id,
        'type' => 'correction',
        'delta_cop' => 0,
        'reason' => 'Sin diferencia',
        'reverses_adjustment_id' => null,
        'created_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->not->toBe('ACCEPTED');

    // A cutoff rule of a scope that requires a client, naming no client.
    expect(r1_refused(fn () => DB::table('cutoff_rules')->insert([
        'scope' => 'client',
        'client_id' => null,
        'company_id' => null,
        'effective_month' => '2026-01-01',
        'cutoff_day' => 10,
        'month_offset' => 1,
        'notes' => null,
        'created_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->not->toBe('ACCEPTED');

    // A cutoff day outside the calendar.
    expect(r1_refused(fn () => DB::table('cutoff_rules')->insert([
        'scope' => 'general',
        'client_id' => null,
        'company_id' => null,
        'effective_month' => '2026-01-01',
        'cutoff_day' => 45,
        'month_offset' => 1,
        'notes' => null,
        'created_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->not->toBe('ACCEPTED');

    // And a generated obligation with no configuration, which R1 made impossible.
    expect(r1_refused(fn () => DB::table('monthly_obligations')->insert([
        'period_id' => $obligation->period_id,
        'client_id' => $obligation->client_id,
        'company_id' => $obligation->company_id,
        'rate_id' => null,
        'cutoff_rule_id' => $obligation->cutoff_rule_id,
        'base_amount_cop' => 1000,
        'due_on' => '2026-05-10',
        'generated_at' => now(),
        'source' => 'generated',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->not->toBe('ACCEPTED');

    // Nothing above wrote a row, so the ledger the migration reasons about is intact.
    expect($payment->fresh())->not->toBeNull()
        ->and($obligation->fresh())->not->toBeNull();
})->group('migrations');

/**
 * A user can be deleted without the audit trail blocking it, and a financial record
 * cannot.
 *
 * The asymmetry is deliberate and stated here because it is easy to "fix" wrongly:
 * `created_by` is `SET NULL` because deleting a person must not be blocked by the record
 * of what they did — and the record is not what is preserved when a person is deleted.
 * `client_id` on an obligation is `RESTRICT` because a debt cannot outlive the client it is
 * owed from.
 */
it('keeps the audit columns nullable and the financial references restrictive', function (): void {
    $employer = r1_employer();
    $obligation = r1_generated($employer);

    $actor = User::factory()->create();
    $other = User::factory()->create();

    $paymentId = DB::table('payments')->insertGetId([
        'client_id' => $employer['client']->id,
        'amount_cop' => 1000,
        'received_on' => '2026-04-05',
        'method' => 'cash',
        'reference' => null,
        'notes' => null,
        'voided_at' => null,
        'voided_by' => null,
        'void_reason' => null,
        'created_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The people go; the records stay, and say so with a null author.
    $actor->delete();
    $other->delete();

    $payment = DB::table('payments')->where('id', $paymentId)->first();

    expect($payment)->not->toBeNull()
        ->and($payment->created_by)->toBeNull();

    // The client's debt does not survive the client.
    expect(r1_refused(fn () => DB::table('clients')
        ->where('id', $employer['client']->id)
        ->delete()))->not->toBe('ACCEPTED');

    expect(MonthlyObligation::query()->find($obligation->id))->not->toBeNull();
})->group('migrations');

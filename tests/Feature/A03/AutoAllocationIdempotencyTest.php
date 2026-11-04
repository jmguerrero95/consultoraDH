<?php

declare(strict_types=1);

use App\Domain\Audit\AuditAction;
use App\Models\AuditEvent;
use App\Models\PaymentAllocation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\Support\SecondConnection;

/**
 * OBS-03C: idempotencia de la aplicación automática de pagos (oldest-first).
 *
 * The endpoint is `POST /api/payments/{payment}/auto-allocate`. A retry with the
 * same key must return the original result — same allocation ids, same order,
 * same amounts, same remaining balance — without creating applications or
 * emitting new events. A new key is a new, valid execution. The same key on a
 * different payment is a 409, and a key whose payment was voided or whose
 * applications were reversed is a controlled rejection that redistributes
 * nothing.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
    $this->actingAs(a03_admin());
});

/**
 * @return array<string, mixed>
 */
function obs03cAutoPayload(?string $key = null): array
{
    return $key === null ? [] : ['idempotency_key' => $key];
}

/**
 * Commit the second session's open transaction after a short delay, from a
 * child process, and return its pid.
 *
 * The parent is blocked on the unique index held by that session, so the delay
 * only has to be long enough for the request to reach it. The child dies with
 * SIGKILL so it never runs the test runner's shutdown handlers twice.
 */
function obs03cCommitAfter(SecondConnection $second, int $microseconds = 500_000): int
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        throw new RuntimeException('No se pudo crear el proceso de la prueba de concurrencia.');
    }

    if ($pid === 0) {
        try {
            usleep($microseconds);
            $second->commit();
        } catch (Throwable) {
            // The parent's bounded statement timeout turns a failed child into a
            // clear test failure instead of a hung suite.
        }

        posix_kill(posix_getpid(), SIGKILL);
    }

    return $pid;
}

/**
 * Committed client, company, period, obligation, payment and one allocation.
 *
 * @return array{client_id: int, company_id: int, period_id: int, obligation_id: int, payment_id: int, allocation_id: int}
 */
function obs03cCommittedFixtures(SecondConnection $second, string $document): array
{
    $clientId = (int) $second->select(
        "INSERT INTO clients (document_type, document_number, first_names, last_names, status, created_at, updated_at)
         VALUES ('CC', ?, 'Ana', 'Concurrente', 'active', now(), now())
         RETURNING id",
        [$document],
    )[0]['id'];

    $companyId = (int) $second->select(
        "INSERT INTO companies (legal_name, status, created_at, updated_at)
         VALUES ('Empresa QA OBS-03C', 'active', now(), now())
         RETURNING id",
    )[0]['id'];

    // A month no A03 helper uses, so this committed row cannot collide with the
    // uncommitted fixtures the test transaction creates.
    $periodId = (int) $second->select(
        "INSERT INTO monthly_periods (period_month, status, opened_at, created_at, updated_at)
         VALUES ('2025-07-01', 'open', now(), now(), now())
         RETURNING id",
    )[0]['id'];

    $obligationId = (int) $second->select(
        "INSERT INTO monthly_obligations
            (period_id, client_id, company_id, base_amount_cop, due_on, generated_at, source, created_at, updated_at)
         VALUES (?, ?, ?, 200000, '2019-08-10', now(), 'manual_correction', now(), now())
         RETURNING id",
        [$periodId, $clientId, $companyId],
    )[0]['id'];

    $paymentId = (int) $second->select(
        "INSERT INTO payments
            (client_id, amount_cop, received_on, method, reference, notes, created_at, updated_at)
         VALUES (?, 300000, '2026-10-15', 'cash', 'recibo-qa-03c', null, now(), now())
         RETURNING id",
        [$clientId],
    )[0]['id'];

    $allocationId = (int) $second->select(
        'INSERT INTO payment_allocations
            (payment_id, obligation_id, amount_cop, created_at, updated_at)
         VALUES (?, ?, 50000, now(), now())
         RETURNING id',
        [$paymentId, $obligationId],
    )[0]['id'];

    return [
        'client_id' => $clientId,
        'company_id' => $companyId,
        'period_id' => $periodId,
        'obligation_id' => $obligationId,
        'payment_id' => $paymentId,
        'allocation_id' => $allocationId,
    ];
}

/**
 * @param  array{client_id: int, company_id: int, period_id: int, obligation_id: int, payment_id: int, allocation_id: int}  $fixtures
 */
function obs03cCleanup(SecondConnection $second, array $fixtures, string $key): void
{
    $second->execute('DELETE FROM auto_allocations WHERE idempotency_key = ?', [$key]);
    $second->execute('DELETE FROM payment_allocations WHERE payment_id = ?', [$fixtures['payment_id']]);
    $second->execute('DELETE FROM payments WHERE id = ?', [$fixtures['payment_id']]);
    $second->execute('DELETE FROM monthly_obligations WHERE id = ?', [$fixtures['obligation_id']]);
    $second->execute('DELETE FROM monthly_periods WHERE id = ?', [$fixtures['period_id']]);
    $second->execute('DELETE FROM companies WHERE id = ?', [$fixtures['company_id']]);
    $second->execute('DELETE FROM clients WHERE id = ?', [$fixtures['client_id']]);
}

// --- 0: the schema carries the global unique key -----------------------------

test('el esquema de prueba tiene el indice unico global de idempotencia', function (): void {
    $index = DB::selectOne(
        "select indexdef from pg_indexes where tablename = 'auto_allocations' "
        ."and indexname = 'auto_allocations_key_unique'"
    );

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('CREATE UNIQUE INDEX')
        ->and($index->indexdef)->toContain('(idempotency_key)')
        ->and($index->indexdef)->not->toContain('WHERE');
});

test('la migracion correctiva reemplaza el indice compuesto por el global', function (): void {
    // Simulate a persistent database where the original migration created the
    // compound index instead of the global one.
    DB::statement('ALTER TABLE auto_allocations DROP CONSTRAINT IF EXISTS auto_allocations_key_unique');
    DB::statement('DROP INDEX IF EXISTS auto_allocations_key_unique');
    DB::statement(
        'CREATE UNIQUE INDEX auto_allocations_payment_key_unique '
        .'ON auto_allocations (payment_id, idempotency_key)'
    );

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_04_110000_fix_auto_allocations_global_unique_key.php');
    $migration->up();

    $global = DB::selectOne(
        "select indexdef from pg_indexes where tablename = 'auto_allocations' "
        ."and indexname = 'auto_allocations_key_unique'"
    );
    $compound = DB::selectOne(
        "select 1 from pg_indexes where tablename = 'auto_allocations' "
        ."and indexname = 'auto_allocations_payment_key_unique'"
    );

    expect($global)->not->toBeNull()
        ->and($global->indexdef)->toContain('(idempotency_key)')
        ->and($compound)->toBeNull();
});

// --- 1: a real execution distributed across two obligations ------------------

test('aplica el pago a dos obligaciones y devuelve el resultado', function (): void {
    $employer = a03_employerFor('solo-cliente');
    $september = a03_payable(100000, '2026-09', $employer);
    $october = a03_payable(100000, '2026-10', $employer);
    $payment = a03_payablePayment(150000, $employer);

    $response = $this->postJson(
        "/api/payments/{$payment->id}/auto-allocate",
        obs03cAutoPayload('auto-dos-obligaciones'),
    );

    $response->assertOk()
        ->assertJsonPath('applied_count', 2)
        ->assertJsonPath('execution_status', 'first')
        ->assertJsonPath('unallocated_amount_cop', 0);

    $allocations = PaymentAllocation::query()
        ->where('payment_id', $payment->id)
        ->orderBy('id')
        ->get();

    expect($allocations)->toHaveCount(2)
        ->and($allocations[0]->obligation_id)->toBe($september->id)
        ->and($allocations[0]->amount_cop)->toBe(100000)
        ->and($allocations[1]->obligation_id)->toBe($october->id)
        ->and($allocations[1]->amount_cop)->toBe(50000)
        ->and($payment->refresh()->unallocatedAmount())->toBe(0);
});

// --- 2: replay returns the historical result, with no new events -------------

test('un reintento identico reconstruye el resultado historico sin eventos nuevos', function (): void {
    $employer = a03_employerFor('solo-cliente');
    a03_payable(100000, '2026-09', $employer);
    a03_payable(100000, '2026-10', $employer);
    $payment = a03_payablePayment(150000, $employer);

    $first = $this->postJson(
        "/api/payments/{$payment->id}/auto-allocate",
        obs03cAutoPayload('auto-reintento'),
    )->assertOk();

    $firstIds = collect($first->json('result.allocations'))->pluck('allocation_id')->all();
    $firstAmounts = collect($first->json('result.allocations'))->pluck('amount_cop')->all();
    $firstRemaining = $first->json('result.unallocated_amount_cop');

    $eventsAfterFirst = AuditEvent::query()
        ->where('action', AuditAction::PaymentAllocated->value)
        ->count();

    $second = $this->postJson(
        "/api/payments/{$payment->id}/auto-allocate",
        obs03cAutoPayload('auto-reintento'),
    )->assertOk();

    expect(collect($second->json('result.allocations'))->pluck('allocation_id')->all())->toBe($firstIds)
        ->and(collect($second->json('result.allocations'))->pluck('amount_cop')->all())->toBe($firstAmounts)
        ->and($second->json('result.unallocated_amount_cop'))->toBe($firstRemaining)
        ->and($second->json('execution_status'))->toBe('replayed')
        ->and(AuditEvent::query()->where('action', AuditAction::PaymentAllocated->value)->count())->toBe($eventsAfterFirst)
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(2);
});

// --- 3: an empty first execution stays empty on replay -----------------------

test('una ejecucion vacia se conserva vacia aunque despues existan obligaciones', function (): void {
    $employer = a03_employerFor('solo-cliente');
    $payment = a03_payablePayment(150000, $employer);

    $first = $this->postJson(
        "/api/payments/{$payment->id}/auto-allocate",
        obs03cAutoPayload('auto-vacia'),
    )->assertOk();

    $first->assertJsonPath('applied_count', 0)
        ->assertJsonPath('execution_status', 'first')
        ->assertJsonPath('unallocated_amount_cop', 150000);

    // A new obligation appears after the empty execution.
    a03_payable(100000, '2026-10', $employer);

    $second = $this->postJson(
        "/api/payments/{$payment->id}/auto-allocate",
        obs03cAutoPayload('auto-vacia'),
    )->assertOk();

    $second->assertJsonPath('applied_count', 0)
        ->assertJsonPath('execution_status', 'replayed')
        ->assertJsonPath('unallocated_amount_cop', 150000);

    expect(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(0)
        ->and($payment->refresh()->unallocatedAmount())->toBe(150000);
});

// --- 4: the same key on another payment is a 409, with no changes ------------

test('la misma clave en otro pago devuelve 409 sin cambios', function (): void {
    $employer = a03_employerFor('solo-cliente');
    a03_payable(100000, '2026-10', $employer);
    $first = a03_payablePayment(150000, $employer);
    $second = a03_payablePayment(150000, $employer);

    $this->postJson(
        "/api/payments/{$first->id}/auto-allocate",
        obs03cAutoPayload('auto-conflicto'),
    )->assertOk();

    $this->postJson(
        "/api/payments/{$second->id}/auto-allocate",
        obs03cAutoPayload('auto-conflicto'),
    )->assertStatus(409)
        ->assertJsonPath('code', 'auto_allocation_idempotency_key_conflict');

    expect(PaymentAllocation::query()->where('payment_id', $second->id)->count())->toBe(0)
        ->and($second->refresh()->unallocatedAmount())->toBe(150000)
        ->and(DB::table('auto_allocations')->where('idempotency_key', 'auto-conflicto')->count())->toBe(1);
});

// --- 5a: a reversed application blocks redistribution ------------------------

test('una aplicacion revertida no permite redistribuir con la misma clave', function (): void {
    $employer = a03_employerFor('solo-cliente');
    a03_payable(100000, '2026-10', $employer);
    $payment = a03_payablePayment(150000, $employer);

    $allocationId = $this->postJson(
        "/api/payments/{$payment->id}/auto-allocate",
        obs03cAutoPayload('auto-revertida'),
    )->assertOk()->json('result.allocations.0.allocation_id');

    $this->postJson("/api/payment-allocations/{$allocationId}/reverse", [
        'reason' => 'Se aplicó al mes equivocado.',
        'confirm' => true,
    ])->assertOk();

    $this->postJson(
        "/api/payments/{$payment->id}/auto-allocate",
        obs03cAutoPayload('auto-revertida'),
    )->assertStatus(409)
        ->assertJsonPath('code', 'auto_allocation_idempotency_key_voided');

    expect(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and(PaymentAllocation::query()->find($allocationId)->isReversed())->toBeTrue()
        ->and($payment->refresh()->unallocatedAmount())->toBe(150000);
});

// --- 5b: a voided payment blocks redistribution ------------------------------

test('un pago anulado no permite redistribuir con la misma clave', function (): void {
    $employer = a03_employerFor('solo-cliente');
    a03_payable(100000, '2026-10', $employer);
    $payment = a03_payablePayment(150000, $employer);

    $this->postJson(
        "/api/payments/{$payment->id}/auto-allocate",
        obs03cAutoPayload('auto-anulada'),
    )->assertOk();

    $this->postJson("/api/payments/{$payment->id}/void", [
        'reason' => 'Pago duplicado en QA.',
        'confirm' => true,
    ])->assertOk();

    $before = PaymentAllocation::query()->where('payment_id', $payment->id)->count();

    $this->postJson(
        "/api/payments/{$payment->id}/auto-allocate",
        obs03cAutoPayload('auto-anulada'),
    )->assertStatus(409)
        ->assertJsonPath('code', 'auto_allocation_idempotency_key_voided');

    expect(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe($before);
});

// --- 6: two concurrent requests with one key: one effective execution --------

test('dos solicitudes concurrentes con una clave producen una sola ejecucion', function (): void {
    DB::statement("SET LOCAL statement_timeout = '15s'");

    $second = SecondConnection::forTestingConfig(config('database.connections.testing'));
    $fixtures = obs03cCommittedFixtures($second, '900000301');
    $key = 'race-auto-'.$fixtures['payment_id'];

    // The other session holds an uncommitted completed execution with this key.
    // The request cannot see it, reaches its own reservation insert, and must wait
    // for the global unique index — which is the atomic guarantee, not an
    // application-level check. When the winner commits, the request reads it back.
    $second->begin();

    $outcome = json_encode([
        'applied_count' => 1,
        'allocations' => [[
            'allocation_id' => $fixtures['allocation_id'],
            'obligation_id' => $fixtures['obligation_id'],
            'amount_cop' => 50000,
        ]],
        'unallocated_amount_cop' => 250000,
    ]);

    $second->select(
        'INSERT INTO auto_allocations
            (idempotency_key, payment_id, status, outcome, created_at, updated_at)
         VALUES (?, ?, ?, ?, now(), now())
         RETURNING id',
        [$key, $fixtures['payment_id'], 'completed', $outcome],
    );

    $pid = obs03cCommitAfter($second);

    $response = $this->postJson(
        "/api/payments/{$fixtures['payment_id']}/auto-allocate",
        obs03cAutoPayload($key),
    );

    pcntl_waitpid($pid, $status);

    $response->assertOk()
        ->assertJsonPath('execution_status', 'replayed')
        ->assertJsonPath('result.allocations.0.allocation_id', $fixtures['allocation_id']);

    expect(DB::table('auto_allocations')->where('idempotency_key', $key)->count())->toBe(1)
        ->and(PaymentAllocation::query()->where('payment_id', $fixtures['payment_id'])->count())->toBe(1);

    // The request still holds locks on its client and payment until the outer test
    // transaction ends. Release them before deleting the committed rows.
    DB::rollBack();
    DB::beginTransaction();

    obs03cCleanup($second, $fixtures, $key);
    $second->disconnect();
});

// --- 7: without a key the previous behaviour is preserved --------------------

test('sin clave conserva el comportamiento anterior', function (): void {
    $employer = a03_employerFor('solo-cliente');
    a03_payable(100000, '2026-10', $employer);
    $payment = a03_payablePayment(150000, $employer);

    $this->postJson("/api/payments/{$payment->id}/auto-allocate")
        ->assertOk()
        ->assertJsonPath('applied_count', 1)
        ->assertJsonMissingPath('execution_status')
        ->assertJsonMissingPath('idempotency_key');

    // A second call without a key applies nothing new: the debt is settled, and
    // no idempotency row is written.
    $this->postJson("/api/payments/{$payment->id}/auto-allocate")
        ->assertOk()
        ->assertJsonPath('applied_count', 0);

    expect(DB::table('auto_allocations')->count())->toBe(0)
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(1);
});

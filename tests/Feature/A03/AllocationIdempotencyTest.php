<?php

declare(strict_types=1);

use App\Domain\Audit\AuditAction;
use App\Models\AuditEvent;
use App\Models\PaymentAllocation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SecondConnection;

/**
 * OBS-03B: idempotencia de aplicaciones manuales de pagos.
 *
 * Un doble clic, un timeout o un reintento HTTP no pueden aplicar dos veces el
 * mismo intento. La clave es opcional: sin ella el flujo anterior sigue intacto y
 * dos aplicaciones sucesivas siguen siendo dos operaciones distintas.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
    $this->actingAs(a03_admin());
});

/**
 * @return array<string, mixed>
 */
function obs03bPayload(int $obligationId, int $amount, ?string $key = null): array
{
    return array_filter([
        'obligation_id' => $obligationId,
        'amount_cop' => $amount,
        'idempotency_key' => $key,
    ], static fn (mixed $value): bool => $value !== null);
}

/**
 * Commit the second session's open transaction after a short delay, from a
 * child process, and return its pid.
 *
 * The parent is already blocked on a lock held by that session, so the delay
 * only has to be long enough for the request to reach it. The child dies with
 * SIGKILL so it never runs the test runner's shutdown handlers twice.
 */
function obs03bCommitAfter(SecondConnection $second, int $microseconds = 500_000): int
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
 * A client, company, period, obligation and payment that the other session can
 * see because they are committed.
 *
 * @return array{client_id: int, company_id: int, period_id: int, obligation_id: int, payment_id: int}
 */
function obs03bCommittedFixtures(SecondConnection $second, string $document): array
{
    $clientId = (int) $second->select(
        "INSERT INTO clients (document_type, document_number, first_names, last_names, status, created_at, updated_at)
         VALUES ('CC', ?, 'Ana', 'Concurrente', 'active', now(), now())
         RETURNING id",
        [$document],
    )[0]['id'];

    $companyId = (int) $second->select(
        "INSERT INTO companies (legal_name, status, created_at, updated_at)
         VALUES ('Empresa QA OBS-03B', 'active', now(), now())
         RETURNING id",
    )[0]['id'];

    // A month no A03 helper uses, so this committed row cannot collide with the
    // uncommitted fixtures the test transaction creates.
    $periodId = (int) $second->select(
        "INSERT INTO monthly_periods (period_month, status, opened_at, created_at, updated_at)
         VALUES ('2026-01-01', 'open', now(), now(), now())
         RETURNING id",
    )[0]['id'];

    $obligationId = (int) $second->select(
        "INSERT INTO monthly_obligations
            (period_id, client_id, company_id, base_amount_cop, due_on, generated_at, source, created_at, updated_at)
         VALUES (?, ?, ?, 200000, '2019-02-10', now(), 'manual_correction', now(), now())
         RETURNING id",
        [$periodId, $clientId, $companyId],
    )[0]['id'];

    $paymentId = (int) $second->select(
        "INSERT INTO payments
            (client_id, amount_cop, received_on, method, reference, notes, created_at, updated_at)
         VALUES (?, 300000, '2026-10-15', 'cash', 'recibo-qa', null, now(), now())
         RETURNING id",
        [$clientId],
    )[0]['id'];

    return [
        'client_id' => $clientId,
        'company_id' => $companyId,
        'period_id' => $periodId,
        'obligation_id' => $obligationId,
        'payment_id' => $paymentId,
    ];
}

function obs03bCleanup(SecondConnection $second, array $fixtures, string $key): void
{
    $second->execute('DELETE FROM payment_allocations WHERE idempotency_key = ?', [$key]);
    $second->execute('DELETE FROM payments WHERE id = ?', [$fixtures['payment_id']]);
    $second->execute('DELETE FROM monthly_obligations WHERE id = ?', [$fixtures['obligation_id']]);
    $second->execute('DELETE FROM monthly_periods WHERE id = ?', [$fixtures['period_id']]);
    $second->execute('DELETE FROM companies WHERE id = ?', [$fixtures['company_id']]);
    $second->execute('DELETE FROM clients WHERE id = ?', [$fixtures['client_id']]);
}

test('sin clave dos aplicaciones sucesivas siguen siendo dos operaciones', function (): void {
    $employer = a03_employerFor('solo-cliente');
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(300000, $employer);

    $this->postJson("/api/payments/{$payment->id}/allocations", obs03bPayload($obligation->id, 80000))
        ->assertStatus(201);

    $this->postJson("/api/payments/{$payment->id}/allocations", obs03bPayload($obligation->id, 80000))
        ->assertStatus(201);

    expect(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(2)
        ->and($payment->refresh()->unallocatedAmount())->toBe(140000)
        ->and($obligation->refresh()->balance())->toBe(40000);
});

test('una clave nueva crea una aplicación y un solo evento', function (): void {
    $employer = a03_employerFor('solo-cliente');
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(300000, $employer);

    $response = $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($obligation->id, 80000, 'alloc-nueva'),
    );

    $response->assertStatus(201)
        ->assertJsonPath('idempotency_key', 'alloc-nueva')
        ->assertJsonPath('allocation.amount_cop', 80000);

    expect(PaymentAllocation::query()->where('idempotency_key', 'alloc-nueva')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditAction::PaymentAllocated->value)->count())->toBe(1);
});

test('un reintento idéntico devuelve la aplicación original sin repetir eventos', function (): void {
    $employer = a03_employerFor('solo-cliente');
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(300000, $employer);

    $first = $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($obligation->id, 80000, 'alloc-reintento'),
    )->assertStatus(201)->json('allocation.id');

    $second = $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($obligation->id, 80000, 'alloc-reintento'),
    );

    $second->assertStatus(200)
        ->assertJsonPath('allocation.id', $first);

    expect(PaymentAllocation::query()->where('idempotency_key', 'alloc-reintento')->count())->toBe(1)
        ->and($payment->refresh()->unallocatedAmount())->toBe(220000)
        ->and($obligation->refresh()->balance())->toBe(120000)
        ->and(AuditEvent::query()->where('action', AuditAction::PaymentAllocated->value)->count())->toBe(1);
});

test('misma clave con importe distinto devuelve conflicto', function (): void {
    $employer = a03_employerFor('solo-cliente');
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(300000, $employer);

    $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($obligation->id, 80000, 'alloc-importe'),
    )->assertStatus(201);

    $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($obligation->id, 70000, 'alloc-importe'),
    )->assertStatus(409)
        ->assertJson(['code' => 'allocation_idempotency_key_conflict']);

    expect(PaymentAllocation::query()->where('idempotency_key', 'alloc-importe')->count())->toBe(1)
        ->and($payment->refresh()->unallocatedAmount())->toBe(220000);
});

test('misma clave con obligación distinta devuelve conflicto', function (): void {
    $employer = a03_employerFor('solo-cliente');
    $october = a03_payable(200000, '2026-10', $employer);
    $september = a03_payable(200000, '2026-09', $employer);
    $payment = a03_payablePayment(400000, $employer);

    $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($october->id, 50000, 'alloc-obligacion'),
    )->assertStatus(201);

    $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($september->id, 50000, 'alloc-obligacion'),
    )->assertStatus(409)
        ->assertJson(['code' => 'allocation_idempotency_key_conflict']);

    expect(PaymentAllocation::query()->where('idempotency_key', 'alloc-obligacion')->count())->toBe(1)
        ->and($september->refresh()->balance())->toBe(200000);
});

test('misma clave con pago distinto devuelve conflicto', function (): void {
    $employer = a03_employerFor('solo-cliente');
    $obligation = a03_payable(200000, '2026-10', $employer);
    $first = a03_payablePayment(300000, $employer);
    $second = a03_payablePayment(300000, $employer);

    $this->postJson(
        "/api/payments/{$first->id}/allocations",
        obs03bPayload($obligation->id, 50000, 'alloc-pago'),
    )->assertStatus(201);

    $this->postJson(
        "/api/payments/{$second->id}/allocations",
        obs03bPayload($obligation->id, 50000, 'alloc-pago'),
    )->assertStatus(409)
        ->assertJson(['code' => 'allocation_idempotency_key_conflict']);

    expect(PaymentAllocation::query()->where('idempotency_key', 'alloc-pago')->count())->toBe(1)
        ->and($second->refresh()->unallocatedAmount())->toBe(300000);
});

test('claves distintas permiten aplicaciones parciales sucesivas', function (): void {
    $employer = a03_employerFor('solo-cliente');
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(300000, $employer);

    $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($obligation->id, 80000, 'alloc-parte-uno'),
    )->assertStatus(201);

    $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($obligation->id, 120000, 'alloc-parte-dos'),
    )->assertStatus(201);

    expect(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(2)
        ->and($payment->refresh()->unallocatedAmount())->toBe(100000)
        ->and($obligation->refresh()->balance())->toBe(0);
});

test('el límite de saldo se sigue aplicando con clave', function (): void {
    $employer = a03_employerFor('solo-cliente');
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(100000, $employer);

    $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($obligation->id, 100001, 'alloc-limite'),
    )->assertStatus(422)
        ->assertJsonPath('reason_code', 'exceeds_payment_balance');

    expect(PaymentAllocation::query()->where('idempotency_key', 'alloc-limite')->count())->toBe(0);

    $other = a03_payablePayment(300000, $employer);

    $this->postJson(
        "/api/payments/{$other->id}/allocations",
        obs03bPayload($obligation->id, 200001, 'alloc-limite-obligacion'),
    )->assertStatus(422)
        ->assertJsonPath('reason_code', 'exceeds_obligation_balance');

    expect(PaymentAllocation::query()->where('idempotency_key', 'alloc-limite-obligacion')->count())->toBe(0);
});

test('una clave de una aplicación revertida no crea otra aplicación', function (): void {
    $employer = a03_employerFor('solo-cliente');
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(300000, $employer);

    $allocationId = $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($obligation->id, 80000, 'alloc-revertida'),
    )->assertStatus(201)->json('allocation.id');

    $this->postJson("/api/payment-allocations/{$allocationId}/reverse", [
        'reason' => 'Se aplicó al mes equivocado.',
        'confirm' => true,
    ])->assertOk();

    $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($obligation->id, 80000, 'alloc-revertida'),
    )->assertStatus(409)
        ->assertJson(['code' => 'allocation_idempotency_key_reversed']);

    expect(PaymentAllocation::query()->where('idempotency_key', 'alloc-revertida')->count())->toBe(1)
        ->and(PaymentAllocation::query()->find($allocationId)->isReversed())->toBeTrue()
        ->and($payment->refresh()->unallocatedAmount())->toBe(300000)
        ->and($obligation->refresh()->balance())->toBe(200000);
});

test('la migracion crea un indice unico total y revierte limpiamente', function (): void {
    $index = DB::selectOne(
        "select indexdef from pg_indexes where tablename = 'payment_allocations' "
        ."and indexname = 'payment_allocations_idempotency_key_unique'"
    );

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('CREATE UNIQUE INDEX')
        ->and($index->indexdef)->not->toContain('WHERE');

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_03_160000_add_idempotency_key_to_payment_allocations_table.php');

    $migration->down();

    expect(Schema::hasColumn('payment_allocations', 'idempotency_key'))->toBeFalse();
    expect(DB::selectOne(
        "select 1 from pg_indexes where indexname = 'payment_allocations_idempotency_key_unique'"
    ))->toBeNull();

    $migration->up();

    expect(Schema::hasColumn('payment_allocations', 'idempotency_key'))->toBeTrue();
    expect(DB::selectOne(
        "select 1 from pg_indexes where indexname = 'payment_allocations_idempotency_key_unique'"
    ))->not->toBeNull();
});

test('dos solicitudes concurrentes con la misma clave sobre pagos distintos resuelven un conflicto', function (): void {
    DB::statement("SET LOCAL statement_timeout = '15s'");

    $second = SecondConnection::forTestingConfig(config('database.connections.testing'));
    $fixtures = obs03bCommittedFixtures($second, '900000101', '2026-01-01');
    $key = 'race-other-'.$fixtures['payment_id'];

    // The other session holds an uncommitted allocation with this key. The request
    // below cannot see it, reaches its own insert, and must wait for the unique
    // index — which is the atomic guarantee, not an application-level check.
    $second->begin();
    $otherAllocationId = (int) $second->select(
        'INSERT INTO payment_allocations
            (payment_id, obligation_id, amount_cop, idempotency_key, created_at, updated_at)
         VALUES (?, ?, 50000, ?, now(), now())
         RETURNING id',
        [$fixtures['payment_id'], $fixtures['obligation_id'], $key],
    )[0]['id'];

    $pid = obs03bCommitAfter($second);

    $employer = a03_employerFor('solo-cliente');
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(300000, $employer);

    $response = $this->postJson(
        "/api/payments/{$payment->id}/allocations",
        obs03bPayload($obligation->id, 50000, $key),
    );

    pcntl_waitpid($pid, $status);

    $response->assertStatus(409)
        ->assertJson(['code' => 'allocation_idempotency_key_conflict']);

    expect(PaymentAllocation::query()->where('idempotency_key', $key)->count())->toBe(1)
        ->and(PaymentAllocation::query()->where('idempotency_key', $key)->value('id'))->toBe($otherAllocationId)
        ->and(AuditEvent::query()->where('action', AuditAction::PaymentAllocated->value)->count())->toBe(0);

    // The request still holds locks on its client and payment until the outer test
    // transaction ends. Release them before deleting the committed rows.
    DB::rollBack();
    DB::beginTransaction();

    obs03bCleanup($second, $fixtures, $key);
    $second->disconnect();
});

test('dos solicitudes concurrentes con la misma clave sobre el mismo pago producen una aplicación', function (): void {
    DB::statement("SET LOCAL statement_timeout = '15s'");

    $second = SecondConnection::forTestingConfig(config('database.connections.testing'));
    $fixtures = obs03bCommittedFixtures($second, '900000102', '2020-04-01');
    $key = 'race-same-'.$fixtures['payment_id'];

    // The other session holds the client lock and an uncommitted allocation with
    // this key. The request blocks on the same client, then sees the committed row
    // and returns it instead of writing a second one.
    $second->begin();
    $second->select('SELECT id FROM clients WHERE id = ? FOR UPDATE', [$fixtures['client_id']]);

    $otherAllocationId = (int) $second->select(
        'INSERT INTO payment_allocations
            (payment_id, obligation_id, amount_cop, idempotency_key, created_at, updated_at)
         VALUES (?, ?, 50000, ?, now(), now())
         RETURNING id',
        [$fixtures['payment_id'], $fixtures['obligation_id'], $key],
    )[0]['id'];

    $pid = obs03bCommitAfter($second);

    $response = $this->postJson(
        "/api/payments/{$fixtures['payment_id']}/allocations",
        obs03bPayload($fixtures['obligation_id'], 50000, $key),
    );

    pcntl_waitpid($pid, $status);

    $response->assertStatus(200)
        ->assertJsonPath('allocation.id', $otherAllocationId);

    expect(PaymentAllocation::query()->where('idempotency_key', $key)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', AuditAction::PaymentAllocated->value)->count())->toBe(0);

    DB::rollBack();
    DB::beginTransaction();

    obs03bCleanup($second, $fixtures, $key);
    $second->disconnect();
});

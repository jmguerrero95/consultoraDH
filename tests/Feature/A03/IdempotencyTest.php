<?php

declare(strict_types=1);

use App\Domain\Audit\AuditAction;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SecondConnection;

/**
 * OBS-03A: idempotencia al registrar pagos.
 *
 * Un doble clic, un timeout o un reintento HTTP no pueden registrar dos veces el
 * mismo pago. La clave es opcional: sin ella el flujo anterior (advertencia
 * `possible_duplicate` y confirmación) sigue intacto.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
    $this->actingAs(userWithRole('Administrator'));
});

/**
 * @return array<string, mixed>
 */
function idempotentPayload(Client $client, array $overrides = []): array
{
    return array_merge([
        'client_id' => $client->id,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
        'notes' => null,
    ], $overrides);
}

/**
 * Commit the second session's open transaction after a short delay, from a
 * child process, and return its pid.
 *
 * The parent is already blocked on a lock held by that session, so the delay
 * only has to be long enough for the request to reach it. The child dies with
 * SIGKILL so it never runs the test runner's shutdown handlers twice.
 */
function idempotencyCommitAfter(SecondConnection $second, int $microseconds = 500_000): int
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

test('registrar sin clave usa el flujo de warning por duplicado', function (): void {
    $client = Client::factory()->create();
    Payment::factory()->create([
        'client_id' => $client->id,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
    ]);

    $response = $this->postJson('/api/payments', idempotentPayload($client));

    $response->assertStatus(409)
        ->assertJson(['code' => 'possible_duplicate']);

    $this->assertDatabaseCount('payments', 1);
});

test('clave idempotencia nueva registra el pago correctamente', function (): void {
    $client = Client::factory()->create();

    $response = $this->postJson('/api/payments', idempotentPayload($client, [
        'idempotency_key' => 'key-nueva-123',
    ]));

    $response->assertStatus(201);

    $this->assertDatabaseCount('payments', 1);

    $payment = Payment::where('idempotency_key', 'key-nueva-123')->first();
    expect($payment)->not->toBeNull()
        ->and($payment->idempotency_key)->toBe('key-nueva-123');
});

test('un reintento identico devuelve el mismo pago y un solo evento', function (): void {
    $client = Client::factory()->create();
    $payload = idempotentPayload($client, ['idempotency_key' => 'key-reintento']);

    $first = $this->postJson('/api/payments', $payload);
    $first->assertStatus(201);

    $paymentId = $first->json('payment.id');

    $second = $this->postJson('/api/payments', $payload);

    $second->assertStatus(200)
        ->assertJsonPath('payment.id', $paymentId);

    expect(Payment::where('idempotency_key', 'key-reintento')->count())->toBe(1)
        ->and(AuditEvent::where('action', AuditAction::PaymentCreated->value)->count())->toBe(1);
});

test('mismo idempotency key en reintento devuelve el pago original', function (): void {
    $client = Client::factory()->create();
    $payment = Payment::factory()->create([
        'client_id' => $client->id,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
        'idempotency_key' => 'key-reintento',
    ]);

    $response = $this->postJson('/api/payments', idempotentPayload($client, [
        'idempotency_key' => 'key-reintento',
    ]));

    $response->assertStatus(200)
        ->assertJsonPath('payment.id', $payment->id);

    $this->assertDatabaseCount('payments', 1);
});

test('mismo importe y fecha sin clave muestra possible_duplicate', function (): void {
    $client = Client::factory()->create();
    Payment::factory()->create([
        'client_id' => $client->id,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
    ]);

    $response = $this->postJson('/api/payments', idempotentPayload($client));

    $response->assertStatus(409)
        ->assertJson(['code' => 'possible_duplicate']);

    $this->assertDatabaseCount('payments', 1);
});

test('misma clave con metodo diferente devuelve conflicto', function (): void {
    $client = Client::factory()->create();
    $key = 'key-metodo';

    $this->postJson('/api/payments', idempotentPayload($client, [
        'idempotency_key' => $key,
    ]))->assertStatus(201);

    $response = $this->postJson('/api/payments', idempotentPayload($client, [
        'method' => 'bank_transfer',
        'idempotency_key' => $key,
    ]));

    $response->assertStatus(409)
        ->assertJson(['code' => 'idempotency_key_conflict']);

    expect(Payment::where('idempotency_key', $key)->count())->toBe(1);
});

test('la referencia y las notas se comparan normalizadas', function (): void {
    $client = Client::factory()->create();
    $key = 'key-normalizada';

    $first = $this->postJson('/api/payments', idempotentPayload($client, [
        'reference' => '  recibo-001  ',
        'notes' => '  nota  ',
        'idempotency_key' => $key,
    ]));

    $first->assertStatus(201);

    $second = $this->postJson('/api/payments', idempotentPayload($client, [
        'reference' => 'recibo-001',
        'notes' => 'nota',
        'idempotency_key' => $key,
    ]));

    $second->assertStatus(200)
        ->assertJsonPath('payment.id', $first->json('payment.id'));

    expect(Payment::where('idempotency_key', $key)->count())->toBe(1);
});

test('misma clave con notas diferentes devuelve conflicto', function (): void {
    $client = Client::factory()->create();
    $key = 'key-notas';

    $this->postJson('/api/payments', idempotentPayload($client, [
        'notes' => 'Primera nota',
        'idempotency_key' => $key,
    ]))->assertStatus(201);

    $response = $this->postJson('/api/payments', idempotentPayload($client, [
        'notes' => 'Otra nota',
        'idempotency_key' => $key,
    ]));

    $response->assertStatus(409)
        ->assertJson(['code' => 'idempotency_key_conflict']);

    expect(Payment::where('idempotency_key', $key)->count())->toBe(1);
});

test('una clave de un pago anulado no registra un pago nuevo', function (): void {
    $client = Client::factory()->create();
    $key = 'key-anulada';

    $payment = Payment::factory()->create([
        'client_id' => $client->id,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
        'idempotency_key' => $key,
        'voided_at' => now(),
        'void_reason' => 'Registrado por error',
    ]);

    $response = $this->postJson('/api/payments', idempotentPayload($client, [
        'idempotency_key' => $key,
    ]));

    $response->assertStatus(409)
        ->assertJson([
            'code' => 'idempotency_key_voided',
            'payment_id' => $payment->id,
        ]);

    expect(Payment::where('idempotency_key', $key)->count())->toBe(1);
});

test('claves distintas con los mismos datos permiten confirmar el duplicado', function (): void {
    $client = Client::factory()->create();

    $this->postJson('/api/payments', idempotentPayload($client, [
        'idempotency_key' => 'key-uno',
    ]))->assertStatus(201);

    $warning = $this->postJson('/api/payments', idempotentPayload($client, [
        'idempotency_key' => 'key-dos',
    ]));

    $warning->assertStatus(409)
        ->assertJson(['code' => 'possible_duplicate']);

    $confirmed = $this->postJson('/api/payments', idempotentPayload($client, [
        'idempotency_key' => 'key-dos',
        'confirm_duplicate' => true,
    ]));

    $confirmed->assertStatus(201);

    expect(Payment::query()->whereIn('idempotency_key', ['key-uno', 'key-dos'])->count())->toBe(2);
});

test('la migracion crea un indice unico total y revierte limpiamente', function (): void {
    $index = DB::selectOne(
        "select indexdef from pg_indexes where tablename = 'payments' and indexname = 'payments_idempotency_key_unique'"
    );

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('CREATE UNIQUE INDEX')
        ->and($index->indexdef)->not->toContain('WHERE');

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_03_150000_add_idempotency_key_to_payments_table.php');

    $migration->down();

    expect(Schema::hasColumn('payments', 'idempotency_key'))->toBeFalse();
    expect(DB::selectOne(
        "select 1 from pg_indexes where indexname = 'payments_idempotency_key_unique'"
    ))->toBeNull();

    $migration->up();

    expect(Schema::hasColumn('payments', 'idempotency_key'))->toBeTrue();
    expect(DB::selectOne(
        "select 1 from pg_indexes where indexname = 'payments_idempotency_key_unique'"
    ))->not->toBeNull();
});

test('dos solicitudes concurrentes con la misma clave producen un solo pago', function (): void {
    DB::statement("SET LOCAL statement_timeout = '15s'");

    $second = SecondConnection::forTestingConfig(config('database.connections.testing'));

    // The client and the first request's payment are committed by the second
    // session: fixtures inside `RefreshDatabase`'s transaction are invisible to
    // the other session, and a lock on an invisible row proves nothing.
    $clientId = (int) $second->select(
        "INSERT INTO clients (document_type, document_number, first_names, last_names, status, created_at, updated_at)
         VALUES ('CC', '900000001', 'Ana', 'Concurrente', 'active', now(), now())
         RETURNING id",
    )[0]['id'];

    $key = 'race-same-'.$clientId;

    $second->begin();

    $paymentId = (int) $second->select(
        "INSERT INTO payments
            (client_id, amount_cop, received_on, method, reference, notes, created_at, updated_at, idempotency_key)
         VALUES (?, 100000, ?, 'cash', 'recibo-001', null, now(), now(), ?)
         RETURNING id",
        [$clientId, now()->toDateString(), $key],
    )[0]['id'];

    $pid = idempotencyCommitAfter($second);

    $response = $this->postJson('/api/payments', [
        'client_id' => $clientId,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
        'idempotency_key' => $key,
    ]);

    pcntl_waitpid($pid, $status);

    $response->assertStatus(200)
        ->assertJsonPath('payment.id', $paymentId);

    expect(Payment::where('idempotency_key', $key)->count())->toBe(1)
        ->and(AuditEvent::where('action', AuditAction::PaymentCreated->value)->count())->toBe(0);

    // The request still holds `FOR UPDATE` on the client and the payment.
    // RefreshDatabase rolls this transaction back at teardown, but the second
    // session cannot delete its committed rows while those locks are held, so
    // release them here and open a fresh transaction for the trait to unwind.
    DB::rollBack();
    DB::beginTransaction();

    $second->execute('DELETE FROM payments WHERE idempotency_key = ?', [$key]);
    $second->execute('DELETE FROM clients WHERE id = ?', [$clientId]);
    $second->disconnect();
});

test('dos solicitudes concurrentes con la misma clave y clientes distintos resuelven un conflicto', function (): void {
    DB::statement("SET LOCAL statement_timeout = '15s'");

    $second = SecondConnection::forTestingConfig(config('database.connections.testing'));

    $otherClientId = (int) $second->select(
        "INSERT INTO clients (document_type, document_number, first_names, last_names, status, created_at, updated_at)
         VALUES ('CC', '900000002', 'Beto', 'Concurrente', 'active', now(), now())
         RETURNING id",
    )[0]['id'];

    $key = 'race-other-'.$otherClientId;

    $second->begin();

    $second->select(
        "INSERT INTO payments
            (client_id, amount_cop, received_on, method, reference, notes, created_at, updated_at, idempotency_key)
         VALUES (?, 100000, ?, 'cash', 'recibo-001', null, now(), now(), ?)
         RETURNING id",
        [$otherClientId, now()->toDateString(), $key],
    );

    $client = Client::factory()->create();

    $pid = idempotencyCommitAfter($second);

    $response = $this->postJson('/api/payments', idempotentPayload($client, [
        'idempotency_key' => $key,
    ]));

    pcntl_waitpid($pid, $status);

    $response->assertStatus(409)
        ->assertJson(['code' => 'idempotency_key_conflict']);

    expect(Payment::where('idempotency_key', $key)->count())->toBe(1)
        ->and(Payment::where('idempotency_key', $key)->value('client_id'))->toBe($otherClientId);

    DB::rollBack();
    DB::beginTransaction();

    $second->execute('DELETE FROM payments WHERE idempotency_key = ?', [$key]);
    $second->execute('DELETE FROM clients WHERE id = ?', [$otherClientId]);
    $second->disconnect();
});

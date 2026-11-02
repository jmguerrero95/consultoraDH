<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Payment;
use Tests\TestCase;

/**
 * OBS-03A: idempotencia al registrar pagos.
 *
 * Verifica que un doble clic, timeout o reintento HTTP no registre dos veces
 * el mismo pago, usando una clave de idempotencia opcional.
 *
 * @refreshDatabase
 */
beforeEach(function (): void
{
    seedPortfolioRoles();
    $this->actingAs(userWithRole('Administrator'));
});

test('registrar sin clave usa el flujo de warning por duplicado', function (): void
{
    // Given: un cliente y un posible duplicado por importe/fecha
    $client = Client::factory()->create();
    Payment::factory()->create([
        'client_id' => $client->id,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
    ]);

    // When: se intenta registrar un pago similar SIN clave
    $response = $this->postJson('/api/payments', [
        'client_id' => $client->id,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
    ]);

    // Then: 409 con possible_duplicate, comportamiento preservado
    $response->assertStatus(409)
        ->assertJson(['code' => 'possible_duplicate']);

    // Y solo un pago existe
    $this->assertDatabaseCount('payments', 1);
});

test('clave idempotencia nueva registra el pago correctamente', function (): void
{
    // Given: un cliente sin pagos previos
    $client = Client::factory()->create();

    // When: se registra con una clave nueva
    $response = $this->postJson('/api/payments', [
        'client_id' => $client->id,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
        'idempotency_key' => 'key-nueva-123',
    ]);

    // Then: 201, pago creado exitosamente
    $response->assertStatus(201);

    // Y existe un pago con esa clave
    $this->assertDatabaseCount('payments', 1);

    // Y el payment tiene la clave almacenada
    $payment = Payment::where('idempotency_key', 'key-nueva-123')->first();
    $this->assertNotNull($payment);
    $this->assertEquals('key-nueva-123', $payment->idempotency_key);
});

test('mismo idempotency key en reintento devuelve el pago original', function (): void
{
    // Given: un cliente y un pago ya registrado con clave
    $client = Client::factory()->create();
    $payment = Payment::factory()->create([
        'client_id' => $client->id,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
        'idempotency_key' => 'key-reintento',
    ]);

    // When: se intenta registrar de nuevo con la misma clave
    $response = $this->postJson('/api/payments', [
        'client_id' => $client->id,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
        'idempotency_key' => 'key-reintento',
    ]);

    // Then: 200, devuelve el pago original (no se crea uno nuevo)
    $response->assertStatus(200)
        ->assertJson(['message' => 'Se registró un pago de 100.000 pesos.']);

    // Y solo un pago existe (la restricción única evita el doble registro)
    $this->assertDatabaseCount('payments', 1);
});

test('mismo importe y fecha sin clave muestra possible_duplicate', function (): void
{
    // Given: un cliente y un pago ya registrado SIN clave
    $client = Client::factory()->create();
    Payment::factory()->create([
        'client_id' => $client->id,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
    ]);

    // When: se intenta registrar con datos idénticos SIN clave
    $response = $this->postJson('/api/payments', [
        'client_id' => $client->id,
        'amount_cop' => 100000,
        'received_on' => now()->toDateString(),
        'method' => 'cash',
        'reference' => 'recibo-001',
    ]);

    // Then: 409 con possible_duplicate
    $response->assertStatus(409)
        ->assertJson(['code' => 'possible_duplicate']);

    // Y solo un pago existe
    $this->assertDatabaseCount('payments', 1);
});

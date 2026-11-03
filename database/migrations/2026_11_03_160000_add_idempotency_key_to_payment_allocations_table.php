<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OBS-03B: clave de idempotencia para aplicaciones manuales de pagos.
 *
 * Una clave opcional por intento de aplicación. El navegador la envía para que
 * un reintento de `POST /api/payments/{payment}/allocations` no cree una segunda
 * aplicación cuando el operador pidió una sola.
 *
 * Casos de comportamiento:
 *   - misma clave + mismo pago, obligación e importe → devuelve la aplicación
 *     original, sin crear otra ni repetir eventos.
 *   - misma clave + pago, obligación o importe distinto → 409.
 *   - clave de una aplicación revertida → 409 explícito; no se aplica de nuevo.
 *   - claves distintas → aplicaciones parciales sucesivas legítimas.
 *   - sin clave → comportamiento anterior intacto.
 *
 * El índice es único y total. PostgreSQL permite múltiples `NULL` en un índice
 * único normal, así que las aplicaciones antiguas sin clave siguen conviviendo
 * sin una cláusula `WHERE` que estreche la garantía.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->string('idempotency_key', 255)->nullable();

            // Índice único total: PostgreSQL permite múltiples NULL y como mucho un
            // valor no nulo. Dos requests concurrentes con la misma clave no pueden
            // escribir dos aplicaciones, y las inserciones sin clave siguen funcionando.
            $table->unique(['idempotency_key'], 'payment_allocations_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->dropUnique('payment_allocations_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};

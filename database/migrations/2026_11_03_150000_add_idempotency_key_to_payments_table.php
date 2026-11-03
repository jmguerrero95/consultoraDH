<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OBS-03A: clave de idempotencia para registro de pagos.
 *
 * Una clave opcional que el navegador puede enviar para que el backend detecte
 * reintentos idénticos y no regístre el pago dos veces. La garantía es atómica
 * en PostgreSQL mediante un índice único sobre la columna.
 *
 * Casos de comportamiento:
 *   - misma clave + mismos datos → devuelve el pago ya registrado, sin crear otro.
 *   - misma clave + datos distintos → 409, sin modificar registros.
 *   - clave de un pago anulado → 409 explícito; nunca se crea un segundo pago.
 *   - clave distinta + mismos datos → flujo normal (advertencia possible_duplicate).
 *   - sin clave → comportamiento anterior, con advertencia por cliente/importe/fecha/ref.
 *
 * El índice es único y total. PostgreSQL permite múltiples `NULL` en un índice
 * único normal, así que los registros antiguos sin clave siguen conviviendo sin
 * una cláusula `WHERE` que estreche la garantía.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 255)->nullable();

            // Índice único total: PostgreSQL permite múltiples NULL y como mucho un
            // valor no nulo. Dos requests concurrentes con la misma clave no pueden
            // registrar dos pagos, y las inserciones sin clave siguen funcionando.
            $table->unique(['idempotency_key'], 'payments_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique('payments_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};

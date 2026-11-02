<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OBS-03A: clave de idempotencia para registro de pagos.
 *
 * Una clave opcional que el navegador puede enviar para que el backend detecte
 * reintentos idénticos y no regístre el pago dos veces. La garantía es
 * atómica en PostgreSQL mediante una restricción parcial única.
 *
 * --8<--8<--8<--8<--8<--8<--8<--8<--8<--8<--8<--8<--8<--8<--8<--8<--8<--8<--8<--8
 *
 * Casos de comportamiento:
 *   - misma clave + mismos datos → devuelve el pago ya registrado, sin crear otro.
 *   - misma clave + datos distintos → 409, sin modificar registros.
 *   - clave distinta + mismos datos → flujo normal (advertencia possible_duplicate).
 *   - sin clave → comportamiento anterior, con advertencia por cliente/importe/fecha/ref.
 *
 * La restricción única es parcial: `WHERE idempotency_key IS NOT NULL`, para que
 * las inserciones sin clave conserven su compatibilidad antigua.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 255)->nullable();

            // Restricción única parcial: una misma clave no puede usarse dos veces.
            // Se permite NULL (compatibilidad con registros antiguos que no la envían).
            $table->unique(['idempotency_key'], 'payments_idempotency_key_unique')
                ->whereNull('idempotency_key')
                ->freshIndex();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key'], 'payments_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
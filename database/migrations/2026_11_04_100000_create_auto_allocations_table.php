<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OBS-03C: tabla de seguimiento para aplicación automática idempotente.
 *
 * Almacena el resultado de una ejecución de auto-allocate para que
 * un reintento con la misma clave devuelva el resultado original
 * sin crear aplicaciones ni emitir eventos nuevos.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_allocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();

            // Clave opcional del idempotencia. Única a nivel global: dos ejecuciones
            // con la misma clave (sea del mismo o de distinto pago) producen un solo resultado.
            $table->string('idempotency_key', 255)->nullable();

            // Estado de la ejecución.
            $table->enum('status', ['pending', 'completed', 'failed', 'voided', 'reversed'])
                ->default('pending');

            // Resultado serializado: aplicaciones creadas, dinero restante, conteo.
            $table->json('outcome')->nullable();

            $table->timestamps();

            // Índice único global sobre la clave de idempotencia.
            $table->unique('idempotency_key', 'auto_allocations_key_unique');

            // Índice por pago para búsquedas de reintento.
            $table->index(['payment_id', 'status'], 'auto_allocations_payment_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_allocations');
    }
};

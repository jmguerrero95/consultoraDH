<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OBS-03C: make the auto-allocation idempotency key globally unique.
 *
 * The original `create_auto_allocations_table` migration was already applied in
 * environments where it created a compound unique index on
 * `(payment_id, idempotency_key)`. Editing that migration does not change an index
 * that already exists, so the same key could still be reused by a second payment.
 *
 * This corrective migration drops the compound index and ensures the global unique
 * index exists. It is additive and idempotent: a database created from the edited
 * original migration already has the global index, and running this there is a
 * no-op. No rows are deleted or rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auto_allocations')) {
            return;
        }

        DB::statement('ALTER TABLE auto_allocations DROP CONSTRAINT IF EXISTS auto_allocations_payment_key_unique');
        DB::statement('DROP INDEX IF EXISTS auto_allocations_payment_key_unique');
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS auto_allocations_key_unique '
            .'ON auto_allocations (idempotency_key)'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('auto_allocations')) {
            return;
        }

        DB::statement('ALTER TABLE auto_allocations DROP CONSTRAINT IF EXISTS auto_allocations_key_unique');
        DB::statement('DROP INDEX IF EXISTS auto_allocations_key_unique');
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS auto_allocations_payment_key_unique '
            .'ON auto_allocations (payment_id, idempotency_key)'
        );
    }
};

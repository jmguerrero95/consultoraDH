<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reminder tracking columns for `operational_tasks`.
 *
 * The columns were first added by hand to the development database while the
 * reminder flow was being built. This migration therefore reconciles rather
 * than assumes: every column is created only when it is missing, and the
 * backfill only writes the default where the value is null. Running it on a
 * database that already has some or all of the columns changes nothing, which
 * is what makes the operational history safe to repair with it.
 *
 * There is deliberately no `->after()`: PostgreSQL does not place a column by
 * position, and asking for one was one of the two errors in the first draft
 * (`AFTER` is not valid PostgreSQL DDL).
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $columns = [
        'reminder_received_at',
        'reminder_dismissed_at',
        'reminder_second_sent_at',
        'reminder_attempts',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('operational_tasks')) {
            return;
        }

        if (! Schema::hasColumn('operational_tasks', 'reminder_received_at')) {
            Schema::table('operational_tasks', function (Blueprint $table): void {
                $table->timestamp('reminder_received_at')->nullable();
            });
        }

        if (! Schema::hasColumn('operational_tasks', 'reminder_dismissed_at')) {
            Schema::table('operational_tasks', function (Blueprint $table): void {
                $table->timestamp('reminder_dismissed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('operational_tasks', 'reminder_second_sent_at')) {
            Schema::table('operational_tasks', function (Blueprint $table): void {
                $table->timestamp('reminder_second_sent_at')->nullable();
            });
        }

        if (! Schema::hasColumn('operational_tasks', 'reminder_attempts')) {
            Schema::table('operational_tasks', function (Blueprint $table): void {
                $table->integer('reminder_attempts')->default(0);
            });
        }

        // Reconcile rows written while the column existed without a value.
        // Nothing is dropped and no row is duplicated.
        DB::table('operational_tasks')
            ->whereNull('reminder_attempts')
            ->update(['reminder_attempts' => 0]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_tasks')) {
            return;
        }

        foreach ($this->columns as $column) {
            if (Schema::hasColumn('operational_tasks', $column)) {
                Schema::table('operational_tasks', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};

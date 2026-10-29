<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERF-01-R1: a durable delivery state for task reminders.
 *
 * The old dispatcher wrote `reminder_sent_at` / `reminder_second_sent_at`
 * *before* sending. A worker that died between that write and the Push call left
 * the row looking delivered, and the second reminder — which has no retry —
 * was never sent again.
 *
 * These four columns are not a general outbox. They record the one reservation
 * that is already happening: which stage is being delivered, by which worker,
 * since when, and whether the in-app notification (the durable, user-visible
 * part) was already written. The final marker is only written once the stage is
 * settled.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $columns = [
        'reminder_delivery_stage',
        'reminder_delivery_token',
        'reminder_delivery_started_at',
        'reminder_delivery_internal_at',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('operational_tasks')) {
            return;
        }

        if (! Schema::hasColumn('operational_tasks', 'reminder_delivery_stage')) {
            Schema::table('operational_tasks', function (Blueprint $table): void {
                $table->string('reminder_delivery_stage', 16)->nullable();
            });
        }

        if (! Schema::hasColumn('operational_tasks', 'reminder_delivery_token')) {
            Schema::table('operational_tasks', function (Blueprint $table): void {
                $table->string('reminder_delivery_token', 64)->nullable();
            });
        }

        if (! Schema::hasColumn('operational_tasks', 'reminder_delivery_started_at')) {
            Schema::table('operational_tasks', function (Blueprint $table): void {
                $table->timestamp('reminder_delivery_started_at')->nullable();
            });
        }

        if (! Schema::hasColumn('operational_tasks', 'reminder_delivery_internal_at')) {
            Schema::table('operational_tasks', function (Blueprint $table): void {
                $table->timestamp('reminder_delivery_internal_at')->nullable();
            });
        }
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

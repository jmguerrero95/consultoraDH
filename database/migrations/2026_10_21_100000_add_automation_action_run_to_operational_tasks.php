<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A06-AUTO-03 — the origin of a task created by an automation.
 *
 * A crash between the INSERT of the task and the recording of the action's
 * `succeeded` used to produce a **second** task on the retry: the effect had
 * happened, and the only record of it was the action's own status, which the
 * crash prevented from being written.
 *
 * The column carries the action run that produced the task, and the unique
 * index is the guarantee: two workers cannot create two tasks for the same
 * action. A search on its own would not have been enough, because two
 * workers can search at the same moment.
 *
 * NULL for every task that was not created by an automation, which is why the
 * index is unique rather than the column being required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_tasks', function (Blueprint $table): void {
            $table->foreignId('automation_action_run_id')
                ->nullable()
                ->after('contribution_sheet_id')
                ->constrained('automation_action_runs')
                ->nullOnDelete();
        });

        // The idempotency guarantee.
        Schema::table('operational_tasks', function (Blueprint $table): void {
            $table->unique('automation_action_run_id', 'operational_tasks_automation_action_run_unique');
        });
    }

    public function down(): void
    {
        Schema::table('operational_tasks', function (Blueprint $table): void {
            $table->dropUnique('operational_tasks_automation_action_run_unique');
            $table->dropConstrainedForeignId('automation_action_run_id');
        });
    }
};

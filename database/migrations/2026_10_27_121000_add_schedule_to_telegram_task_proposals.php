<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The interpreted schedule of a proposed task or reminder.
 *
 * A reminder is not the sentence "mañana a las 9". The proposal stores the
 * resolved date (`due_on`) and the executable instant (`reminder_at`) so the
 * task created on confirmation is the one `ReminderDispatcher` already reads.
 * Both are nullable: a plain task needs neither, and a reminder missing them is
 * refused before it becomes a proposal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_task_proposals', function (Blueprint $table): void {
            if (! Schema::hasColumn('telegram_task_proposals', 'due_on')) {
                $table->date('due_on')->nullable()->after('description');
            }

            if (! Schema::hasColumn('telegram_task_proposals', 'reminder_at')) {
                $table->timestamp('reminder_at')->nullable()->after('due_on');
            }
        });
    }

    public function down(): void
    {
        Schema::table('telegram_task_proposals', function (Blueprint $table): void {
            if (Schema::hasColumn('telegram_task_proposals', 'reminder_at')) {
                $table->dropColumn('reminder_at');
            }

            if (Schema::hasColumn('telegram_task_proposals', 'due_on')) {
                $table->dropColumn('due_on');
            }
        });
    }
};

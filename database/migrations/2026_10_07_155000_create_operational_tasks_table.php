<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_tasks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('novelty_id')->nullable()->constrained('client_novelties')->nullOnDelete();
            $table->foreignId('document_request_id')->nullable()->constrained('client_document_requests')->nullOnDelete();
            $table->foreignId('contribution_sheet_id')->nullable()->constrained('contribution_sheets')->nullOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();

            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('pending');

            $table->date('due_on')->nullable();
            $table->timestamp('reminder_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['assigned_to', 'status', 'due_on'], 'operational_tasks_assignee_status_due_index');
            $table->index(['reminder_at', 'reminder_sent_at'], 'operational_tasks_reminder_index');
            $table->index(['client_id', 'status'], 'operational_tasks_client_status_index');
        });

        DB::statement("ALTER TABLE operational_tasks ADD CONSTRAINT operational_tasks_priority_check CHECK (priority IN ('low', 'normal', 'high', 'urgent'))");
        DB::statement("ALTER TABLE operational_tasks ADD CONSTRAINT operational_tasks_status_check CHECK (status IN ('pending', 'in_progress', 'done', 'cancelled'))");
        DB::statement(<<<'SQL'
            ALTER TABLE operational_tasks ADD CONSTRAINT operational_tasks_completion_shape_check
            CHECK (
                (status <> 'done' OR completed_at IS NOT NULL)
                AND
                (status NOT IN ('pending', 'in_progress') OR completed_at IS NULL)
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE operational_tasks ADD CONSTRAINT operational_tasks_reminder_shape_check
            CHECK (reminder_sent_at IS NULL OR reminder_at IS NOT NULL)
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE operational_tasks ADD CONSTRAINT operational_tasks_subject_check
            CHECK (
                client_id IS NOT NULL
                OR novelty_id IS NOT NULL
                OR document_request_id IS NOT NULL
                OR contribution_sheet_id IS NOT NULL
                OR description IS NOT NULL
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_tasks');
    }
};

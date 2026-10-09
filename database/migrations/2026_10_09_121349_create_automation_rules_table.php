<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(false);
            $table->string('trigger_type'); // audit_action, schedule, task_due, document_request_due, receivable_overdue, support_sla_breach
            $table->jsonb('trigger_config');
            $table->jsonb('condition_config')->nullable();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['active', 'trigger_type']);
            $table->index(['owner_user_id', 'active']);
            $table->index('next_run_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};

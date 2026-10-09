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
        Schema::create('support_queues', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->foreignId('fallback_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('escalation_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('first_response_minutes')->nullable();
            $table->unsignedInteger('next_response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();
            $table->unsignedInteger('warning_minutes_before')->nullable();
            $table->unsignedInteger('fallback_email_delay_minutes')->nullable();
            $table->boolean('telegram_escalation_enabled')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['active', 'is_default']);
        });

        // Partial unique index for at most one active default queue (PostgreSQL)
        DB::statement('CREATE UNIQUE INDEX support_queues_one_active_default ON support_queues (is_default) WHERE is_default = true AND active = true');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS support_queues_one_active_default');
        Schema::dropIfExists('support_queues');
    }
};

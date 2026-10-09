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
        Schema::create('automation_action_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained('automation_runs')->restrictOnDelete();
            $table->foreignId('automation_action_id')->constrained('automation_actions')->restrictOnDelete();
            $table->string('status'); // succeeded, failed, skipped, blocked
            $table->jsonb('result_snapshot')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();

            $table->unique(['automation_run_id', 'automation_action_id']);
            $table->index(['automation_run_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_action_runs');
    }
};

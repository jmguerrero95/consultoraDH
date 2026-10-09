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
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->restrictOnDelete();
            $table->string('occurrence_key');
            $table->string('status'); // succeeded, partial, failed, blocked
            $table->jsonb('trigger_snapshot')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();

            $table->unique(['automation_rule_id', 'occurrence_key']);
            $table->index(['automation_rule_id', 'status', 'started_at']);
            $table->index('occurrence_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};

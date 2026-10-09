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
        Schema::create('support_sla_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->restrictOnDelete();
            $table->string('metric'); // first_response, next_response, resolution
            $table->timestamp('due_at');
            $table->string('level'); // warning, breach
            $table->timestamp('emitted_at')->useCurrent();

            $table->unique(['conversation_id', 'metric', 'due_at', 'level']);
            $table->index(['due_at', 'level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_sla_events');
    }
};

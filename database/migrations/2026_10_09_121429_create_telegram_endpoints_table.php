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
        Schema::create('telegram_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('chat_id');
            $table->boolean('enabled')->default(true);
            $table->jsonb('event_preferences')->nullable(); // e.g., ["support_sla_breach", "urgent_unassigned", "automation_alert", "document_overdue"]
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['enabled', 'chat_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_endpoints');
    }
};

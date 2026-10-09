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
        Schema::create('automation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('action_type'); // internal_notification, create_task, send_email, send_telegram, assign_support_queue, assign_support_user, set_support_priority
            $table->jsonb('config');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['automation_rule_id', 'position']);
            $table->index(['automation_rule_id', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_actions');
    }
};

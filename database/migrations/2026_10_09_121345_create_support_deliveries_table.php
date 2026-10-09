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
        Schema::create('support_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_message_id')->nullable()->constrained('support_messages')->nullOnDelete();
            $table->foreignId('conversation_id')->constrained('support_conversations')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel'); // email, push, telegram
            $table->string('status'); // pending, sent, failed, suppressed
            $table->string('dedupe_key')->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'channel', 'status']);
            $table->index(['recipient_user_id', 'channel', 'status']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_deliveries');
    }
};

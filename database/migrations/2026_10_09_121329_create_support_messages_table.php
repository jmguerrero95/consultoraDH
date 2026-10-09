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
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->restrictOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_kind'); // staff, client, external, system
            $table->string('message_kind'); // message, note, system
            $table->string('channel'); // portal, staff, email, automation, system
            $table->text('body_text')->nullable();
            $table->boolean('client_visible')->default(true);
            $table->string('external_message_id')->nullable();
            $table->string('ingress_fingerprint')->nullable();
            $table->string('email_from')->nullable();
            $table->string('email_to')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
            $table->index('external_message_id');
            $table->index('ingress_fingerprint');
            $table->index(['conversation_id', 'client_visible', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};

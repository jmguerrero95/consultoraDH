<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scope a Telegram endpoint to a single conversation.
     *
     * The column is nullable on purpose: a null value means "admin-wide, this
     * endpoint receives every alert it subscribes to", which is how the desk
     * was configured before this column existed. A non-null value narrows the
     * endpoint to that one conversation, so an escalation about a sensitive
     * client can be routed to a private chat without leaking it to the general
     * operations group.
     *
     * Deleting a conversation removes its private endpoints rather than
     * orphaning a row that points at a record nobody can reach.
     */
    public function up(): void
    {
        Schema::table('telegram_endpoints', function (Blueprint $table) {
            $table->foreignId('conversation_id')
                ->nullable()
                ->after('label')
                ->constrained('support_conversations')
                ->cascadeOnDelete();

            // The escalation query filters on conversation plus the event
            // preference, so the pair is worth an index on its own.
            $table->index(['conversation_id', 'enabled'], 'telegram_endpoints_conversation_enabled_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_endpoints', function (Blueprint $table) {
            $table->dropIndex('telegram_endpoints_conversation_enabled_index');
            $table->dropConstrainedForeignId('conversation_id');
        });
    }
};
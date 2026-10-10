<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a delivery exist without a conversation.
     *
     * A06.7 states that Telegram is an admin alert channel and never mirrors a
     * conversation. Those deliveries therefore had no conversation to point at,
     * but the column was NOT NULL, so every Telegram send died on insert with
     * a not-null violation — the alert could never be recorded, let alone
     * deduplicated. The escalation listener and the automation `send_telegram`
     * action both hit this.
     *
     * NULL now means "channel-level delivery, not tied to a conversation".
     * Client and staff deliveries keep their conversation.
     */
    public function up(): void
    {
        Schema::table('support_deliveries', function (Blueprint $table) {
            $table->foreignId('conversation_id')
                ->nullable()
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_deliveries', function (Blueprint $table) {
            $table->foreignId('conversation_id')
                ->nullable(false)
                ->change();
        });
    }
};
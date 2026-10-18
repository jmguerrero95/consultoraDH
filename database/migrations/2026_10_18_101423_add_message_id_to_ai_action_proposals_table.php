<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A07-R5-R3-R1 — a proposal belongs to the turn that produced it.
 *
 * The proposal is created when a write tool is invoked; the user message that
 * triggered the tool is the one the proposal logically belongs beside. If a
 * proposal existed before this migration, its `message_id` stays null and it
 * appears in a secondary "Propuestas anteriores" section rather than being
 * forced into a turn it did not originate from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_action_proposals', function (Blueprint $table): void {
            $table->foreignId('message_id')
                ->nullable()
                ->constrained('ai_messages')
                ->nullOnDelete()
                ->after('ai_conversation_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_action_proposals', function (Blueprint $table): void {
            $table->dropForeign(['message_id']);
            $table->dropColumn('message_id');
        });
    }
};

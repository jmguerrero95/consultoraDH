<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §10 — a turn is a durable entity, not something that exists only in a reply.
 *
 * Before this, a question only became a row when the model *succeeded*: a turn
 * that failed left nothing on the server, so an F5 lost the question and there
 * was no way to retry it idempotently. This adds the state a turn needs:
 *
 *   - `client_turn_id` — the browser's id for the attempt. A retry reuses it, so
 *     the server can tell "this is the same question again" from a new one and
 *     never runs an accepted turn twice. Unique per conversation, so two accounts
 *     can never collide on one id and read each other's text.
 *   - `status` — `pending` (accepted, not started), `processing` (in flight),
 *     `failed` (terminal for this attempt, retryable) or `completed`. Only a
 *     `completed` turn is history the model is shown; a `failed` one is kept so
 *     the person still sees the question they asked, but it is not fed back.
 *   - `error` — a short, safe reason. Never the provider's raw text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_messages', function (Blueprint $table): void {
            $table->string('client_turn_id', 64)->nullable();
            $table->string('status', 20)->default('completed');
            $table->text('error')->nullable();

            // One attempt per conversation. Nullable ids are allowed to repeat,
            // so historical rows with no id are unaffected.
            $table->unique(['ai_conversation_id', 'client_turn_id']);
            $table->index(['ai_conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table): void {
            $table->dropUnique(['ai_conversation_id', 'client_turn_id']);
            $table->dropIndex(['ai_conversation_id', 'status']);
            $table->dropColumn(['client_turn_id', 'status', 'error']);
        });
    }
};

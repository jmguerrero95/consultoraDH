<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook delivery deduplication.
 *
 * Telegram retries an update when it does not receive a prompt 200. The retry
 * carries the same `update_id`, and without a claim the bot would run the turn
 * again: a second query is harmless, a confirmed write is not. The unique index
 * on `update_id` is what decides the winner, so two concurrent deliveries race
 * in the database rather than in PHP.
 *
 * The row stores no payload: only the id, the chat it came from, and when it was
 * processed. Retention is a week, enough for Telegram's retry window and short
 * enough that the table does not become a transcript.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telegram_updates')) {
            return;
        }

        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->string('chat_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};

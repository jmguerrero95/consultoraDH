<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private Telegram binding.
 *
 * A chat used to be bindable to anything by sending `/start <id>`: the webhook
 * accepted the number the sender typed, so any chat could claim any
 * conversation. Binding now requires a token that only an authenticated,
 * active employee can obtain, that is stored hashed, that expires, and that is
 * consumed exactly once.
 *
 * `user_id` on `telegram_endpoints` is what makes an authorized query
 * answerable: the bot asks the business services *as that employee*, with that
 * employee's permissions, instead of answering as an anonymous chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('telegram_endpoints', 'user_id')) {
            Schema::table('telegram_endpoints', function (Blueprint $table): void {
                $table->foreignId('user_id')
                    ->nullable()
                    ->after('conversation_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('telegram_binding_tokens')) {
            Schema::create('telegram_binding_tokens', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                // Only the SHA-256 of the token is stored. The plaintext exists
                // once, in the response that issued it.
                $table->string('token_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'consumed_at']);
            });
        }

        if (! Schema::hasTable('telegram_task_proposals')) {
            Schema::create('telegram_task_proposals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('chat_id');
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('status', 20)->default('pending');
                $table->timestamp('expires_at');
                $table->timestamp('confirmed_at')->nullable();
                $table->foreignId('operational_task_id')
                    ->nullable()
                    ->constrained('operational_tasks')
                    ->nullOnDelete();
                $table->timestamps();

                $table->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_task_proposals');
        Schema::dropIfExists('telegram_binding_tokens');

        if (Schema::hasColumn('telegram_endpoints', 'user_id')) {
            Schema::table('telegram_endpoints', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};

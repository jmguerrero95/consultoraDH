<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The private assistant state of one bound Telegram chat.
 *
 * `conversation_id` on this table is a *support* conversation, used by the
 * alerting side of the bot. It must never be reused for the assistant: the two
 * mean different things and mixing them would let a support thread become an
 * AI context. `ai_conversation_id` is a separate, nullable reference owned by
 * the bound employee.
 *
 * `context` is the small deterministic memory the bot needs when no provider is
 * available: which client the previous answer was about, and which document was
 * last found. It is scoped to the endpoint, cleared whenever the binding changes
 * owner or is disabled, and never contains message history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('telegram_endpoints', 'ai_conversation_id')
            && Schema::hasColumn('telegram_endpoints', 'context')) {
            return;
        }

        Schema::table('telegram_endpoints', function (Blueprint $table): void {
            if (! Schema::hasColumn('telegram_endpoints', 'ai_conversation_id')) {
                $table->foreignId('ai_conversation_id')
                    ->nullable()
                    ->after('conversation_id')
                    ->constrained('ai_conversations')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('telegram_endpoints', 'context')) {
                $table->jsonb('context')->nullable()->after('ai_conversation_id');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('telegram_endpoints', 'ai_conversation_id')) {
            Schema::table('telegram_endpoints', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('ai_conversation_id');
            });
        }

        if (Schema::hasColumn('telegram_endpoints', 'context')) {
            Schema::table('telegram_endpoints', function (Blueprint $table): void {
                $table->dropColumn('context');
            });
        }
    }
};

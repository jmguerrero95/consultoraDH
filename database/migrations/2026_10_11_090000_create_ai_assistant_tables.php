<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The A07 assistant's own storage: conversations, messages and proposed writes.
 *
 * ## Small on purpose
 *
 * Three tables, no embeddings, no vector column and no retrieval index. The
 * assistant answers from the application's own services through a closed tool
 * registry, so there is nothing to embed.
 *
 * ## Why a proposed write is a row and not a token
 *
 * A confirmation that travelled to the browser and back could be replayed with
 * edited arguments, because whatever the browser sends is whatever the server
 * receives. Persisting the validated arguments at proposal time means the
 * confirmation carries only a key: the executed work is the work that was
 * proposed, not the work the browser asked for.
 *
 * `single_use` is a status rather than a deleted row, so an attempted replay
 * leaves evidence that a replay was attempted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id();

            // The owner. Every read and write of a conversation is scoped to
            // this column; there is no shared or global conversation.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Optional client context, set explicitly by the person and never
            // inferred from the conversation's text.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title')->default('Conversación');

            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();

            // user | assistant | tool. Tool messages carry the registry's
            // structured result and are data, never instructions.
            $table->string('role');

            $table->text('content');

            // Which provider answered, and which model it named. Identifiers
            // only: an API key is a credential and is not stored here.
            $table->string('provider')->nullable();
            $table->string('model')->nullable();

            $table->timestamps();

            $table->index(['ai_conversation_id', 'id']);
        });

        Schema::create('ai_action_proposals', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();

            // The person who asked, not the person the assistant is acting for.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('tool');

            // The arguments as validated at proposal time. The confirmation
            // executes these, whatever the browser sends afterwards.
            $table->jsonb('arguments');

            // pending | confirmed | rejected | expired
            $table->string('status')->default('pending');

            $table->timestamp('expires_at');

            $table->timestamp('executed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_action_proposals');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
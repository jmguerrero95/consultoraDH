<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers that a conversation has held private business data.
 *
 * ## The defect this closes
 *
 * Provider eligibility was decided from the *current* turn alone. A conversation
 * that began with a client's portfolio — and therefore has that client's name and
 * figures in its history — could, on a later public question, be classified as a
 * web turn and sent, history included, to a provider whose free tier may train on
 * what it receives.
 *
 * A boolean on the conversation is the smallest durable signal that survives a
 * reload: once true, every later turn is routed only to providers eligible for
 * private data, whatever the surface question looks like.
 *
 * Additive and nullable-free with a default, so the migration is safe on the
 * existing table and no historical import is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->boolean('contains_client_data')->default(false)->after('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->dropColumn('contains_client_data');
        });
    }
};

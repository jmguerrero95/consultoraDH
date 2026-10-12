<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a suggestion was actually put in front of someone.
 *
 * ## Why this is a table and not a counter on `ai_suggestions`
 *
 * `ai_suggestions` holds one row per problem per person, so a problem that is
 * offered, lapses, and is offered again later the same day is still one row.
 * Counting rows therefore counted *distinct problems*, not interruptions - and
 * the daily ceiling is supposed to bound interruptions. A person whose two worst
 * problems each got re-raised four times in a day was being interrupted eight
 * times and counted two.
 *
 * An append-only log makes the ceiling mean what it says, and it doubles as the
 * record of what was actually shown, which is what makes the number auditable
 * rather than a counter nobody can check.
 *
 * ## Deduplication and the two ledgers
 *
 * `dedupe_key` stays on the suggestion: it is what decides whether this is the
 * *same problem*, and that is a property of the problem, not of the offer.
 * `offered_at` here is a property of the interruption. Keeping them apart is
 * what lets a dismissed suggestion stay dismissed forever while its cooldowns
 * keep counting correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_suggestion_offers', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('ai_suggestion_id')->nullable()->constrained()->nullOnDelete();

            // Copied rather than joined, so the log still reads correctly after
            // the suggestion it refers to is gone.
            $table->string('dedupe_key', 191);

            // Where the person was when they were interrupted. A record of *how*
            // often someone is interrupted, and where, is the only way to notice
            // that a screen became a place the assistant never stops talking.
            $table->string('source_module', 64);
            $table->string('source_operation', 64);

            $table->timestamp('offered_at');

            $table->timestamps();

            // The daily ceiling: one user's offers inside a window.
            $table->index(['user_id', 'offered_at']);

            // Cooldown: the last time this person was told about this problem.
            $table->index(['user_id', 'dedupe_key', 'offered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestion_offers');
    }
};

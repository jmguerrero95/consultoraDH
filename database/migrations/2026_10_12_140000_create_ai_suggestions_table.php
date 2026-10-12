<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proactive assistance: what the assistant noticed, and why it decided to speak.
 *
 * ## A suggestion is a row, not a message
 *
 * The reason for storing this rather than emitting a hint and forgetting it is
 * the anti-intrusion requirement: the same problem must not be raised twice, a
 * dismissed suggestion must stay dismissed, and a user must be able to see what
 * was proposed and on what evidence. None of that is possible if the suggestion
 * only ever existed as a rendered element.
 *
 * ## Every field here answers "how do I know this is real?"
 *
 * `evidence` carries the observed facts the suggestion is built from, so a
 * reviewer can check a claim against the database rather than take it on
 * trust. `trigger_category` names the kind of signal, not the message. The
 * `explanation` is generated from those facts at request time and is therefore
 * never stored as a template.
 *
 * ## Deduplication and cooldown live in the schema
 *
 * `dedupe_key` is unique per user, so a repeated signal cannot produce a second
 * row even if two requests race. `expires_at` is what makes silence possible:
 * a signal that was true last month is not worth interrupting anyone about now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_suggestions', function (Blueprint $table): void {
            $table->id();

            // Who may see this. Every read is scoped here; a suggestion is as
            // private as the conversation it belongs to.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('ai_conversation_id')->nullable()->constrained('ai_conversations')->cascadeOnDelete();

            // The module and operation the person was in when the signal was
            // seen. Advisory context, not a foreign key: a module can be
            // renamed without rewriting history.
            $table->string('source_module', 64);

            $table->string('source_operation', 64);

            // import_inconsistency | overdue_concentration | period_delta |
            // unresolved_rejections | missing_correlation
            $table->string('trigger_category', 48);

            // The entity the suggestion is about, so it can be linked and so the
            // same entity is not suggested about twice in different words.
            $table->string('entity_type', 48)->nullable();

            $table->string('entity_reference', 64)->nullable();

            // The observed facts. An array because a signal is rarely a single
            // number: it is a count, a ratio, a period and a comparison.
            $table->jsonb('evidence');

            // What to do about it, in terms the person can act on.
            $table->text('suggested_action');

            // 0..1. Stored so a threshold can be applied without recomputing
            // against facts that may since have changed.
            $table->float('relevance_score')->default(0);

            // Uniquely identifies "the same problem" for this person. Computed
            // from the category and the entity, never from the wording, so a
            // reworded suggestion is still recognised as the same one.
            $table->string('dedupe_key', 191);

            // available | dismissed | accepted | expired
            $table->string('status')->default('available');

            $table->timestamp('expires_at');

            $table->timestamp('resolved_at')->nullable();

            // When this problem was last put in front of someone, as opposed to
            // when the row was first created.
            //
            // These are not the same column for a reason: a problem that was
            // offered, ignored, and is still true gets offered again, and the
            // cooldown has to run from the last time it was actually said.
            // Measuring it from `created_at` would let a problem be re-offered
            // the moment its first offer expired, however briefly that offer had
            // been on screen.
            $table->timestamp('last_offered_at')->nullable();

            $table->timestamps();

            // The hot path is "show me the one thing to say now", which is a
            // user's available suggestion ordered by recency.
            $table->index(['user_id', 'status', 'expires_at']);

            // One row per person per problem, for the life of the problem.
            //
            // The cooldown exists because a problem can outlive its offer, and a
            // problem that is re-offered is still the same problem - so it
            // refreshes its row instead of accumulating a second one. Unique, so
            // two simultaneous detections collide rather than both winning.
            $table->unique(['user_id', 'dedupe_key']);

            // The cooldown and the daily ceiling both ask "when did we last say
            // something to this person", which is an index on the pair.
            $table->index(['user_id', 'last_offered_at']);

            $table->index(['ai_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestions');
    }
};

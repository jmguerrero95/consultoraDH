<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for a model to have had a go at a suggestion.
 *
 * ## Why this is separate from the deterministic sentence
 *
 * The deterministic explanation is derived from the evidence by code and is
 * therefore always true. A reasoned one is produced by a model and is only
 * *probably* true, which is a different kind of claim and deserves a different
 * column so the two can never be confused for one another.
 *
 * `present()` reads the reasoned text only when it exists and the row is still
 * fresh, and falls back to the generated sentence otherwise. Nothing depends on
 * a reasoned explanation existing.
 *
 * ## Why `reasoned_evidence_fingerprint` is stored
 *
 * The evidence can change between one offer and the next: a payment lands, the
 * count drops. Reasoning written for the old figures is then a sentence about
 * numbers that no longer exist, so the fingerprint of the evidence it was
 * written from is kept and compared before the text is reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_suggestions', function (Blueprint $table): void {
            $table->text('reasoned_explanation')->nullable()->after('suggested_action');
            $table->text('reasoned_action')->nullable()->after('reasoned_explanation');

            $table->string('reasoned_model', 120)->nullable()->after('reasoned_action');

            // When the reasoning was produced, and over which evidence. Compared
            // before the text is shown again; see the class docblock.
            $table->timestamp('reasoned_at')->nullable()->after('reasoned_model');
            $table->char('reasoned_evidence_fingerprint', 64)->nullable()->after('reasoned_at');

            // "Has this problem already had a model look at it?" The answer is
            // per person and per problem, so it is indexed with them.
            $table->index(['user_id', 'dedupe_key', 'reasoned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_suggestions', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'dedupe_key', 'reasoned_at']);

            $table->dropColumn([
                'reasoned_explanation',
                'reasoned_action',
                'reasoned_model',
                'reasoned_at',
                'reasoned_evidence_fingerprint',
            ]);
        });
    }
};

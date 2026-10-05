<?php

declare(strict_types=1);

use App\Domain\Imports\ImportActionState;
use App\Domain\Imports\ImportActionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A04: the exact plan, persisted, and the single explanation of what `apply` will do.
 *
 * ## Acceptance criterion 11, and why this table exists
 *
 * "The preview and the apply share exactly the same persisted plan."
 *
 * The obvious implementation computes the plan when the preview is requested and computes
 * it again when `apply` is pressed. That is two explanations of one batch, and they diverge
 * the moment anything changes in between — a resolution, a re-parse, a row somebody edited
 * in another tab. The reviewer approved one set of changes and something else happened.
 *
 * So the plan is written to a table. The preview screen renders these rows. The apply
 * walks these rows. There is no second computation, and no way for the two to disagree
 * because there is only one of it.
 *
 * ## The unique fingerprint is what makes a rebuild safe
 *
 * Rebuilding the plan after a resolution throws the old rows away. If an id were reused, or
 * if two actions for the same natural key could coexist, the plan would apply twice or in an
 * order nobody approved. `batch_fingerprint` is unique across the import, so a rebuild
 * either produces exactly the same plan or fails loudly.
 *
 * ## `source_row_ids` is the answer to "where did this come from?"
 *
 * §13 asks it for every applied record, and it is a `bigint[]` rather than a join table
 * because the question is "show me the lines" — a single read of one action — and a join
 * would make the review screen's most common request an N+1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_import_actions', function ($table): void {
            $table->id();

            $table->foreignId('legacy_import_id')
                ->constrained('legacy_imports')
                ->cascadeOnDelete();

            /**
             * Application order within the batch.
             *
             * Not an accident of iteration: masters before relationships, relationships
             * before affiliations and rates, because a rate cites a client and a company and
             * an affiliation can hang off a relationship.
             */
            $table->unsignedInteger('ordinal');

            $table->string('action_type', 40);

            /**
             * What identifies the thing being written.
             *
             * `company:900123456`, `client:CC:12345678`,
             * `relationship:900123456:12345678:2024-01-01`, `rate:12345678:900123456:2024-01`.
             * A string rather than four nullable columns because the shape differs per type
             * and a row with three nulls and one value is harder to read than one string.
             */
            $table->string('natural_key', 255);

            /** What to write. Redacted: no credential and no full line of a person. */
            $table->jsonb('payload');

            /** The staged rows this action was derived from. §13 provenance. JSONB array of ids. */
            $table->jsonb('source_row_ids')->nullable();

            /**
             * SHA-256 over type + natural key + canonical payload.
             *
             * Unique inside the import, so rebuilding a plan cannot double an action and
             * two genuinely different actions cannot collide by accident.
             */
            $table->char('batch_fingerprint', 64);

            $table->string('state', 12)->default(ImportActionState::Planned->value);

            /** Filled in after the row lands, so provenance can be followed forward. */
            $table->string('target_type', 64)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            /** Why a `skipped` action was skipped. A person reads this. */
            $table->text('skip_reason')->nullable();

            /** Sanitised failure for this one action. Never a stack trace. */
            $table->text('failure_message')->nullable();

            $table->timestampsTz();

            $table->index(['legacy_import_id', 'ordinal']);
            $table->index(['legacy_import_id', 'action_type']);
        });

        DB::statement('ALTER TABLE legacy_import_actions ADD CONSTRAINT legacy_import_actions_type_check
            CHECK (action_type IN ('.
            implode(',', array_map(
                static fn (ImportActionType $type): string => "'{$type->value}'",
                ImportActionType::cases(),
            )).'))');

        DB::statement('ALTER TABLE legacy_import_actions ADD CONSTRAINT legacy_import_actions_state_check
            CHECK (state IN ('.
            implode(',', array_map(
                static fn (ImportActionState $state): string => "'{$state->value}'",
                ImportActionState::cases(),
            )).'))');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX legacy_import_actions_batch_unique
                ON legacy_import_actions (legacy_import_id, batch_fingerprint)
            SQL);

        /**
         * Provenance is all or nothing.
         *
         * An action that wrote something but does not say which row produced it cannot
         * answer §13's question, so the link is not optional once the state says it ran.
         */
        DB::statement('ALTER TABLE legacy_import_actions ADD CONSTRAINT legacy_import_actions_target_check
            CHECK ((state = \'applied\') = (target_type IS NOT NULL AND target_id IS NOT NULL))');

        // A skip that does not say why is indistinguishable from a bug, and a reviewer
        // reading the history has no way to tell the two apart.
        DB::statement('ALTER TABLE legacy_import_actions ADD CONSTRAINT legacy_import_actions_skip_check
            CHECK ((state = \'skipped\') = (skip_reason IS NOT NULL))');

        DB::statement('ALTER TABLE legacy_import_actions ADD CONSTRAINT legacy_import_actions_ordinal_check
            CHECK (ordinal >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_actions');
    }
};

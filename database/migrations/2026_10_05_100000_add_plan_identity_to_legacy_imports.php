<?php

declare(strict_types=1);

use App\Domain\Imports\ImportRetirementPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A04-R1: plan identity — a review approves a *revision*, not "whatever the plan is now".
 *
 * ## What was wrong
 *
 * The plan is rebuilt whenever a resolution lands. `apply` re-read `legacy_import_actions`
 * at the moment it ran, so the operator's confirmation and the write were two different
 * things whenever a rebuild happened in between — another tab resolving an issue, the plan
 * job finishing late, a reviewer hitting rebuild twice. §5.4 forbids precisely that: the
 * preview must be the explanation that actually applies.
 *
 * The audit made it concrete: `apply()` accepted **no** request body, so nothing bound the
 * click to the revision that was on screen.
 *
 * ## What a revision is
 *
 * `plan_revision` counts rebuilds. `plan_digest` is the SHA-256 of the canonical JSON of
 * every action's `(ordinal, action_type, natural_key, payload, source_row_ids)` — the parts
 * a reviewer can actually see on the "Plan exacto" screen.
 *
 * Both are needed. A revision number alone does not detect a rebuild that produced a
 * different plan under the same number, and a digest alone does not order revisions or
 * survive a rebuild that legitimately produced no change. The pair is the identity:
 *
 * - `apply` submits the revision and digest it was shown;
 * - a mismatch is `409 stale_plan`, not a write;
 * - a rebuild that changes nothing keeps the digest and *advances* the revision, so an
 *   approval of an unchanged plan is still the approval the operator gave.
 *
 * ## Why the digest is recomputed on write rather than trusted from the job
 *
 * `BuildLegacyImportPlan` computes it after the rebuild, in the same transaction that
 * replaced the actions. A digest that a caller could supply would be a digest of a wish.
 *
 * ## `plan_built_at` and the decision
 *
 * Resolving an issue does not bump the revision by itself. It bumps it when the plan that
 * incorporates it is built, because until then the stored plan does not include the answer.
 * `plan_decision_policy` records the batch-level interpretation rule the operator chose
 * (§17.4's retirement policy) so the digest can change when that rule changes, and so the
 * applied plan can be explained without re-reading a deleted row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_imports', function ($table): void {
            /**
             * Bumped every time the persisted plan is replaced. Starts at 0: no plan has
             * been built yet, and 0 is not a revision anybody can be holding in a browser.
             */
            $table->unsignedInteger('plan_revision')->default(0);

            /** SHA-256 of the canonical action JSON. NULL until a plan exists. */
            $table->char('plan_digest', 64)->nullable();

            $table->timestampTz('plan_built_at')->nullable();

            /**
             * The batch-level retirement interpretation rule, §17.4.
             *
             * Kept on the import and not only on its issues because it is not a per-row
             * answer: it changes how every "retiro sin fecha" row is read at once, so it is
             * part of what a reviewer approves.
             */
            $table->string('interpretation_policy', 40)->nullable();

            /** The answers that were in force when this plan was built. §13 provenance. */
            $table->jsonb('plan_decisions')->nullable();
        });

        // A revision without a plan is a revision of nothing; a plan without a revision
        // cannot be ordered. Both halves are one fact.
        DB::statement('ALTER TABLE legacy_imports ADD CONSTRAINT legacy_imports_plan_identity_check
            CHECK ((plan_revision = 0 AND plan_digest IS NULL AND plan_built_at IS NULL)
                OR (plan_revision > 0 AND plan_digest IS NOT NULL AND plan_built_at IS NOT NULL))');

        // The policy is a closed set, and an unknown value would mean the apply could not
        // reproduce the plan it is being asked to write.
        //
        // Generated from `ImportRetirementPolicy::cases()` rather than typed out, because an
        // earlier draft of this migration listed three invented names and every write of a real
        // policy failed the CHECK — the same drift `legacy_imports_code_check` in the A04
        // migration exists to prevent, reintroduced one file over. `PlanIdentityTest` asserts the
        // two stay in step.
        DB::statement(sprintf(
            'ALTER TABLE legacy_imports ADD CONSTRAINT legacy_imports_interpretation_policy_check
                CHECK (interpretation_policy IS NULL OR interpretation_policy IN (%s))',
            implode(',', array_map(
                static fn (ImportRetirementPolicy $policy): string => "'{$policy->value}'",
                ImportRetirementPolicy::cases(),
            )),
        ));

        // The digest is 64 lowercase hex characters or nothing. Not enforced as a regex
        // because `char(64)` already caps the length and a hand-written uppercase digest
        // would simply fail to match, which is the same outcome as a stale plan.
        DB::statement('CREATE INDEX legacy_imports_plan_digest_index
            ON legacy_imports (plan_digest) WHERE plan_digest IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS legacy_imports_plan_digest_index');

        Schema::table('legacy_imports', function ($table): void {
            $table->dropColumn([
                'plan_revision',
                'plan_digest',
                'plan_built_at',
                'interpretation_policy',
                'plan_decisions',
            ]);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A04-R2: let a finding stop applying without pretending anybody answered it.
 *
 * ## Why a column and not a reuse of `resolved_at`
 *
 * `BuildLegacyImportPlan::reconcileIssues()` has to do three different things to a stored
 * finding, and `resolved_at` can only express one of them:
 *
 * 1. still derivable, still open — leave it alone;
 * 2. still derivable, somebody answered it — keep the answer;
 * 3. **no longer derivable** — the history changed and the situation the finding described is
 *    gone.
 *
 * A04-R1 expressed (3) by setting `blocking = false` and `severity = info` and leaving
 * `resolved_at` as it was, which meant a *never answered* finding and a *superseded* one were
 * indistinguishable: both looked "closed", and an unanswered blocker was indistinguishable
 * from a question that had been withdrawn.
 *
 * The alternative — writing `resolved_at` — is worse, and is the one this migration exists to
 * avoid. §4.1 defines `resolved_at` as "a person answered this". Writing it from a rebuild would
 * put a reviewer who never saw the dialog into the audit trail as though they had, and would
 * make a file with unanswered blockers look approved.
 *
 * `superseded_at` therefore carries only what is true: the reconstruction stopped producing this
 * finding. `resolved_at` stays null, so "nobody answered" remains answerable.
 *
 * ## Deliberately nullable, deliberately not indexed with the rest
 *
 * No index: `superseded_at` is read once per rebuild as a filter, always with
 * `legacy_import_id` already in the predicate, and the existing index on
 * `(legacy_import_id, fingerprint)` covers the scan. Adding one would be a second write on every
 * rebuild's hot path for a query that is linear in one import's findings either way.
 *
 * ## The unique index is unchanged, and that matters
 *
 * This migration does not touch `UNIQUE (legacy_import_id, fingerprint)`. That constraint is
 * correct — one row per finding per import is the invariant — and the bug it exposed in A04-R1
 * was in the *fingerprints*, not the index: thirteen distinct disappearances hashed identically,
 * so the second one collided. The fingerprints are now built from a subject both halves share.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_import_issues', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('legacy_import_issues', function (Blueprint $table): void {
            $table->dropColumn('superseded_at');
        });
    }
};

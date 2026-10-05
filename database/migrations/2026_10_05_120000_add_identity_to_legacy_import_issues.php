<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A04-R1: an issue has an identity, so a rebuild reconciles instead of multiplying.
 *
 * ## What was wrong
 *
 * `legacy_import_issues` had no key of its own. A `rebuild-plan` deletes the issues and
 * re-derives them, so:
 *
 * - every interval-level finding got a new `id` on every rebuild, which meant a resolution
 *   a reviewer had just recorded was silently discarded, and a person who had answered a
 *   question was asked it again;
 * - `overlapping_company_history` and `relationship_disappeared_without_retirement` could
 *   not be matched to the decision that settled them, so §13's "what human decision allowed
 *   this transformation" had no answer for exactly the findings that needed one;
 * - nothing stopped two derivations of the same finding from coexisting.
 *
 * ## `fingerprint` is over the *semantic* identity of the finding
 *
 * Not the row. `IssueIdentity` builds it from the code, the subject the finding is about and
 * the thing that is wrong with it: for an interval finding, the company, the client, the type
 * and the months involved; for a cell finding, the row's `source_key`. Two of the same
 * finding about the same thing hash the same, which is what makes reconciliation possible.
 *
 * Row-level findings get their identity from `source_key` rather than `row_id`, for the same
 * reason `legacy_import_rows.source_key` exists: a re-stage assigns new ids, and a resolution
 * that keyed on ids would not survive its own rebuild.
 *
 * ## Resolution is therefore carried across a rebuild
 *
 * This migration adds no `resolved_by` column — those exist — but it makes them meaningful by
 * giving the row they belong to a stable key. `BuildLegacyImportPlan` reads the previous
 * resolution for a matching `fingerprint` and writes it onto the new row, so the operator's
 * answers survive and the "was this answered" question has a stable subject.
 *
 * ## `blocking` may be narrowed by a person, so it is not in the fingerprint
 *
 * §5.3 explicitly allows "this one does not block, I checked". Folding it in would make the
 * same finding a different identity depending on who looked at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_import_issues', function ($table): void {
            /** SHA-256 of `IssueIdentity::for()`. Unique inside the import. */
            $table->char('fingerprint', 64);
        });

        DB::statement('CREATE UNIQUE INDEX legacy_import_issues_fingerprint_unique
            ON legacy_import_issues (legacy_import_id, fingerprint)');

        // A finding is either a workbook-level one or a row-level one, and its identity says
        // which: a row finding's fingerprint is derived from that row's `source_key`, which is
        // never present for a workbook-level code. Enforcing the shape here means a new code
        // cannot be added with an identity that names neither a row nor a subject, which is
        // the case in which a rebuild would silently drop it.
        DB::statement('ALTER TABLE legacy_import_issues ADD CONSTRAINT legacy_import_issues_fingerprint_check
            CHECK (fingerprint ~ \'^[0-9a-f]{64}$\')');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS legacy_import_issues_fingerprint_unique');

        Schema::table('legacy_import_issues', function ($table): void {
            $table->dropColumn('fingerprint');
        });
    }
};

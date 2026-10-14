<?php

declare(strict_types=1);

use App\Domain\Imports\LegacyImportIssue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let `legacy_import_issues` hold `contradictory_relationship_starts`.
 *
 * ## The same trap as the action-type constraint, one table over
 *
 * `legacy_import_issues_code_check` is generated from `LegacyImportIssue::cases()` when the table
 * migration runs, so adding a case to the enum does not change the constraint already in the
 * database. The plan builder began inserting a code the database rejected.
 *
 * The symptom is worth repeating because it is not a crash and not a validation error: the plan
 * job died, the batch never left `review`, and Apply answered **409 `wrong_state`** — a message
 * about the batch's state that was completely unrelated to the actual cause. Anything that touches
 * the plan and reads like a state machine has to be checked against the logs before it is believed.
 *
 * The constraint is kept for the same reason the action-type one was: it is what makes §18's "the
 * code is an API/UI contract" enforceable rather than aspirational, and it is the last place an
 * unimplemented finding can be caught.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE legacy_import_issues DROP CONSTRAINT legacy_import_issues_code_check');

        DB::statement('ALTER TABLE legacy_import_issues ADD CONSTRAINT legacy_import_issues_code_check
            CHECK (code IN ('.
            implode(',', array_map(
                static fn (LegacyImportIssue $issue): string => "'{$issue->value}'",
                LegacyImportIssue::cases(),
            )).'))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE legacy_import_issues DROP CONSTRAINT legacy_import_issues_code_check');

        // Rows the restored constraint would reject are removed rather than left in a state no
        // reader of this database should be able to observe.
        DB::statement(
            'DELETE FROM legacy_import_issues WHERE code = \'contradictory_relationship_starts\''
        );

        DB::statement('ALTER TABLE legacy_import_issues ADD CONSTRAINT legacy_import_issues_code_check
            CHECK (code IN ('.
            implode(',', array_map(
                static fn (LegacyImportIssue $issue): string => "'{$issue->value}'",
                array_filter(
                    LegacyImportIssue::cases(),
                    static fn (LegacyImportIssue $issue): bool => $issue !== LegacyImportIssue::ContradictoryRelationshipStarts,
                ),
            )).'))');
    }
};

<?php

declare(strict_types=1);

use App\Domain\Imports\ImportActionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let `legacy_import_actions` hold `record_affiliation_observation`.
 *
 * ## Why a separate migration rather than a line in the previous one
 *
 * `legacy_import_actions_type_check` is generated from `ImportActionType::cases()` **when the
 * table migration runs**. Adding a case to the enum therefore does not change the constraint
 * that is already in the database, and the plan builder started inserting a value the database
 * rejected — with the failure surfacing as a 409 `wrong_state` from Apply, because the plan job
 * had died and the batch never reached `review`.
 *
 * That message is worth a sentence, because it is actively misleading: nothing was wrong with
 * the batch's state. The symptom is what made this slow to find, so it is written down here.
 *
 * The constraint stays. It is the mechanism behind `ImportActionType`'s docblock claim that
 * "an action with a type nobody implements is a plan row the apply cannot execute" — the
 * database is the last place that claim is enforced, and dropping it to make a new type work
 * would remove the guarantee for every future type as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE legacy_import_actions DROP CONSTRAINT legacy_import_actions_type_check');

        DB::statement('ALTER TABLE legacy_import_actions ADD CONSTRAINT legacy_import_actions_type_check
            CHECK (action_type IN ('.
            implode(',', array_map(
                static fn (ImportActionType $type): string => "'{$type->value}'",
                ImportActionType::cases(),
            )).'))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE legacy_import_actions DROP CONSTRAINT legacy_import_actions_type_check');

        // Back to the ten types the table was created with. The observation is dropped too,
        // because leaving rows that the restored constraint would reject is not a state any
        // reader of this database should be able to observe.
        DB::statement(
            'DELETE FROM legacy_import_actions WHERE action_type = \'record_affiliation_observation\''
        );

        DB::statement('ALTER TABLE legacy_import_actions ADD CONSTRAINT legacy_import_actions_type_check
            CHECK (action_type IN ('.
            implode(',', array_map(
                static fn (ImportActionType $type): string => "'{$type->value}'",
                array_filter(
                    ImportActionType::cases(),
                    static fn (ImportActionType $type): bool => $type !== ImportActionType::RecordAffiliationObservation,
                ),
            )).'))');
    }
};

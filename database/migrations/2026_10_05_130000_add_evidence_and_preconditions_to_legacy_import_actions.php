<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A04-R1: an action must be able to say what it saw, and refuse to write if it changed.
 *
 * ## `source_row_ids` was carrying the wrong ids
 *
 * §5.4 and §13 both require the action to keep the staged rows it was derived from. It kept
 * **Excel row numbers**. Three consequences, all of them failures of §13's question:
 *
 * - a row number is not a key: row 7 exists in each of the ten sheet-months, so the ids
 *   collided, and `array_unique` on the way in made the set lossy;
 * - a re-stage assigns new `legacy_import_rows.id`s, so the numbers pointed at nothing after
 *   a rebuild;
 * - `legacy_import_rows` has a foreign key on `legacy_import_issues.row_id` precisely so a
 *   link is enforced, and here it was not one.
 *
 * The audit confirmed the root cause: `StageLegacyImport` collected staged ids into `$rowIds`
 * and then never passed them on, so no DTO carried the id and the caller fell back to the
 * number it had to hand.
 *
 * Existing rows are left alone rather than backfilled, and deliberately so. A bare Excel row
 * number does not say which of the ten sheet-months it came from, so a backfill would have to
 * guess — and a guessed provenance link is the exact defect being fixed. A stored row number
 * is treated as *no* provenance until the plan is rebuilt, which happens before any apply,
 * and the trigger below then refuses anything that is not a real staged id.
 *
 * ## `source_evidence` is for the human, `source_row_ids` is for the database
 *
 * §17.5 wants the reviewer to expand an action to the sheet and row it came from. §13 wants
 * the database to be able to enforce the link. Two columns, because a JSONB array of ids
 * cannot render "ENERO 2026 · fila 42" and a human-readable string cannot be verified.
 *
 * ## `preconditions` closes the window between preview and apply
 *
 * §11 requires an existing-data comparison before anything is written, and §12.3 requires
 * all-or-nothing. Both are defeated by a row changing in between: the plan says "create this
 * relationship", the reviewer approves it, and by the time apply runs somebody has created it
 * by hand — the apply would then either fail obscurely or, worse, silently treat it as its
 * own write.
 *
 * `preconditions` records, per action, the observable state the plan was built against:
 * whether the target already existed, and the values the comparison read. The apply
 * re-checks them in the same transaction that writes, and refuses with a domain error when
 * they no longer hold. This is the same TOCTOU discipline A03 already uses for
 * `BillingTopologyLock`, applied to the comparison rather than to the lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_import_actions', function ($table): void {
            /**
             * Human-readable provenance for §17.5 and §13's audit metadata.
             *
             * `{"sheets": ["ENERO 2026"], "rows": [42], "cells": "A42:P42"}` — positions and
             * labels only, redacted by construction because they never contain cell contents.
             */
            $table->jsonb('source_evidence')->nullable();

            /**
             * What the plan assumed about the world at build time.
             *
             * Shape per action type, e.g.
             * `{"target_exists": false, "observed": {"ended_on": null, "ended_on_precision": null}}`
             * or `{"target_exists": true, "observed": {"monthly_amount_cop": 4200000}}`.
             *
             * The apply re-reads these under the same lock it writes under and refuses when
             * they differ. An action with no preconditions — every action written before this
             * migration — is treated as "nothing was assumed", which is the only reading that
             * does not invent a check the plan never made.
             */
            $table->jsonb('preconditions')->nullable();
        });

        /**
         * Real staged-row ids, or nothing.
         *
         * Every element must be an integer that exists in `legacy_import_rows`. Written as a
         * trigger because a CHECK cannot reach another table, and this is the one place where
         * "the provenance is real" is the difference between a claim and a fact — §13 asks
         * which line of the Excel originated a record, and an unenforced array of numbers is
         * an answer that can be wrong.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_import_actions_source_rows_exist()
            RETURNS trigger AS $$
            DECLARE
                missing integer;
            BEGIN
                IF NEW.source_row_ids IS NULL THEN
                    RETURN NEW;
                END IF;

                IF jsonb_typeof(NEW.source_row_ids) <> 'array' THEN
                    RAISE EXCEPTION
                        'source_row_ids must be an array of legacy_import_rows.id, got %',
                        jsonb_typeof(NEW.source_row_ids)
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Anything that is not a positive integer is not an id, and casting it would
                -- either error obscurely or silently resolve to 0.
                IF EXISTS (
                    SELECT 1 FROM jsonb_array_elements(NEW.source_row_ids) AS element
                    WHERE jsonb_typeof(element) <> 'number'
                       OR element::text !~ '^[0-9]+$'
                       OR (element::text)::bigint < 1
                ) THEN
                    -- The offending element is named, not just the column. A bare "must contain
                    -- only ids" sent the reader to the JSONB and told them nothing; the value is
                    -- an integer this module wrote, so naming it is safe and is the whole
                    -- difference between a five-second and a five-minute diagnosis.
                    RAISE EXCEPTION
                        'source_row_ids must contain only legacy_import_rows.id values; found %',
                        (SELECT element::text
                           FROM jsonb_array_elements(NEW.source_row_ids) AS element
                           WHERE jsonb_typeof(element) <> 'number'
                              OR element::text !~ '^[0-9]+$'
                              OR (element::text)::bigint < 1
                           LIMIT 1)
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT count(*) INTO missing
                FROM jsonb_array_elements(NEW.source_row_ids) AS element
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM legacy_import_rows
                    WHERE legacy_import_rows.id = (element::text)::bigint
                      AND legacy_import_rows.legacy_import_id = NEW.legacy_import_id
                );

                IF missing > 0 THEN
                    RAISE EXCEPTION
                        'source_row_ids references % row(s) that do not belong to import % (first: %)',
                        missing, NEW.legacy_import_id,
                        (SELECT (element::text)::bigint
                         FROM jsonb_array_elements(NEW.source_row_ids) AS element
                         WHERE NOT EXISTS (
                             SELECT 1 FROM legacy_import_rows
                             WHERE legacy_import_rows.id = (element::text)::bigint
                               AND legacy_import_rows.legacy_import_id = NEW.legacy_import_id
                         )
                         LIMIT 1)
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER legacy_import_actions_source_rows_exist
                BEFORE INSERT OR UPDATE OF source_row_ids ON legacy_import_actions
                FOR EACH ROW EXECUTE FUNCTION legacy_import_actions_source_rows_exist()
        SQL);

        // §7.3 and §10: evidence is either there or it is a count, and both are objects.
        DB::statement('ALTER TABLE legacy_import_actions ADD CONSTRAINT legacy_import_actions_evidence_check
            CHECK (source_evidence IS NULL OR jsonb_typeof(source_evidence) = \'object\')');

        DB::statement('ALTER TABLE legacy_import_actions ADD CONSTRAINT legacy_import_actions_preconditions_check
            CHECK (preconditions IS NULL OR jsonb_typeof(preconditions) = \'object\')');

        /**
         * §12.3: an import cannot be `applied` while an action of it is still `planned`.
         *
         * The row-level CHECK at migration 140000 constrains each action individually, which a
         * stranded `planned` row satisfies (`false = false`). The audit's finding was that a
         * plan whose actions could not all execute still reached `applied`, with the
         * un-executed ones counted as successes. A trigger is the only thing that can see
         * across rows, and this is the assertion that makes the transaction's final
         * transition honest.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_imports_no_stranded_actions()
            RETURNS trigger AS $$
            DECLARE
                stranded integer;
            BEGIN
                IF NEW.status = 'applied' AND (TG_OP = 'INSERT' OR OLD.status IS DISTINCT FROM 'applied') THEN
                    SELECT count(*) INTO stranded
                    FROM legacy_import_actions
                    WHERE legacy_import_actions.legacy_import_id = NEW.id
                      AND legacy_import_actions.state = 'planned';

                    IF stranded > 0 THEN
                        RAISE EXCEPTION
                            'import % cannot be applied: % planned action(s) were not executed',
                            NEW.id, stranded
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER legacy_imports_no_stranded_actions
                BEFORE UPDATE OF status ON legacy_imports
                FOR EACH ROW EXECUTE FUNCTION legacy_imports_no_stranded_actions()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS legacy_imports_no_stranded_actions ON legacy_imports');
        DB::statement('DROP FUNCTION IF EXISTS legacy_imports_no_stranded_actions()');
        DB::statement('DROP TRIGGER IF EXISTS legacy_import_actions_source_rows_exist ON legacy_import_actions');
        DB::statement('DROP FUNCTION IF EXISTS legacy_import_actions_source_rows_exist()');

        Schema::table('legacy_import_actions', function ($table): void {
            $table->dropColumn(['source_evidence', 'preconditions']);
        });
    }
};

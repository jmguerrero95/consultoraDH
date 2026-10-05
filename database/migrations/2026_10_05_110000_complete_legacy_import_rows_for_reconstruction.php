<?php

declare(strict_types=1);

use App\Domain\Affiliations\SocialSecurityEntityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A04-R1: staging has to carry everything the reconstruction needs, without the file.
 *
 * ## Why this migration exists
 *
 * §5's flow says `resoluciones humanas → plan exacto`, and §15's `rebuild-plan` has to
 * rebuild the history from the database. So every fact the plan depends on must survive in
 * `legacy_import_rows`. Several did not, and the plan silently changed meaning when it was
 * rebuilt rather than failing:
 *
 * | Missing | Consequence of rebuilding without it |
 * |---|---|
 * | `affiliation_date_raw` was present but never read back | a `MARZO 2026` cell re-diagnosed as `missing_date` instead of `ambiguous_date` |
 * | `affiliation_date_precision` never read back | month precision flattened to `day`; §8.3's "no mostrar un mes como un día" violated |
 * | `affiliation_date_suggestion` absent | `use_suggested_date` had nothing to apply |
 * | the ARL title/row split collapsed into one column | §9.4's evidence priority could not be re-evaluated and `company_arl_metadata_conflict` was undetectable |
 * | the title's permitted risks not stored | `CompanyTitle::fromStored()` returned `[]`, losing §9.4's allow-list on every rebuild |
 * | the NIT verification digit not stored | §7.2's "same NIT, different digit" conflict undetectable after a rebuild |
 * | `operator_ref` / `payroll_ref` / `source_reference` / `retirement_month_token` in the schema, never written | §13's "which line, which decision" answer was incomplete |
 * | token *outcomes* not stored | a `NO CAJA` cell rebuilt as `absent`, which reads as "not observed" rather than "observed negative" |
 *
 * ## `source_key` is the line's identity, not its contents
 *
 * `fingerprint` is over the *normalised payload*, which is exactly what §7.3 wants to compare
 * — and exactly what makes it useless for provenance. Change a date, and the line that was
 * wrong is now a different key.
 *
 * `source_key` is a SHA-256 over position only: sheet, month, row number, block index. It
 * answers "the same line of the same file" and survives a re-parse, a re-staging and a plan
 * rebuild. §13 provenance needs that; §18's interval issues need it to keep their identity
 * across a rebuild instead of multiplying.
 *
 * Uniqueness is `(legacy_import_id, source_key)`: two different lines of one file are two
 * different keys, and one line cannot be staged twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_import_rows', function ($table): void {
            /**
             * Identity of the *position*: SHA-256 of
             * `sheet_name|sheet_month_key|source_row_number|block_index`.
             *
             * Distinct from `fingerprint`, which is over meaning. See the class docblock for
             * why both are needed.
             */
            $table->char('source_key', 64);

            /**
             * The NIT check digit, §7.2.
             *
             * Without it, a company title whose NIT base matches an existing company but
             * whose digit does not is indistinguishable from a clean match — and A02 treats
             * those differently, because one is a typo and the other is a different taxpayer.
             */
            $table->string('company_verification_digit', 1)->nullable();

            /**
             * The redacted company title as written.
             *
             * §9.4's `company_arl_metadata_conflict` compares the title's ARL provider
             * against the header's. Storing only the resolved value made the disagreement
             * unrecoverable after the fact, which is the one thing §13 asks for.
             */
            $table->text('company_title_raw')->nullable();

            /**
             * §8.3's *suggestion*, kept beside the problem.
             *
             * §17.4 offers "use the suggested date" for `invalid_affiliation_date`. Without
             * this column the choice was a button that changed nothing.
             */
            $table->date('affiliation_date_suggestion')->nullable();

            /**
             * The parser's own diagnosis: `ambiguous_date`, `missing_date`, `invalid_format`…
             *
             * Stored so a rebuild reproduces the diagnosis instead of re-deriving one. A cell
             * the parse called `ambiguous_date` arriving at the plan as `missing_date` meant
             * the plan and the issue list disagreed about the same cell.
             */
            $table->string('affiliation_date_problem', 32)->nullable();

            /**
             * §10's distinction between a blank amount and a bad one, and §7.1's invalid
             * email. Both are warnings rather than blockers, so the row is staged — and both
             * would otherwise be re-derived as a *different* warning on rebuild.
             */
            $table->string('amount_problem', 32)->nullable();
            $table->string('email_problem', 32)->nullable();

            /**
             * §9.4's two ARL evidence sources, kept apart.
             *
             * `arl_token_title` is the provider named in the company title; `arl_token_row` is
             * the header/row column. §9.4 ranks title above header and demands an issue when
             * they disagree. One merged column cannot express a disagreement, and made the
             * fallback unrecoverable: a rebuild could not tell a title-derived provider from
             * a row-derived one.
             */
            $table->string('arl_token_title', 120)->nullable();
            $table->string('arl_token_row', 120)->nullable();

            /**
             * Which of the two §9.4 used, and whether they contradicted each other.
             *
             * `title`, `header`, or `conflict`. Recorded rather than recomputed so the
             * applied plan can be explained without re-reading a file that may since have been
             * replaced by a re-upload of the same hash.
             */
            $table->string('arl_evidence', 12)->nullable();

            /** The title's `RIESGOS 1,2,3` allow-list. `CompanyTitle::fromStored()` lost it. */
            $table->jsonb('arl_permitted_risks')->nullable();

            /**
             * Each social-security cell's *outcome*, by type.
             *
             * §9.1 makes `NO`, `SIN CAJA`, `NINGUNA` evidence that a person was **not**
             * affiliated that month — not evidence that nothing was observed. The token string
             * alone does not survive that distinction: a rebuild read an empty normalised
             * token and produced "no observation", which is how an affiliation disappears
             * from the reconstruction.
             *
             * Shape: `{"EPS": {"token": "SALUD TOTAL", "problem": "suggestion"}, …}`, keys
             * limited to the four entity types.
             */
            $table->jsonb('entity_states')->nullable();
        });

        // A line of a file is staged once. Without this, a re-parse that produced two blocks
        // for the same position would double every count in the review screen.
        DB::statement('CREATE UNIQUE INDEX legacy_import_rows_source_key_unique
            ON legacy_import_rows (legacy_import_id, source_key)');

        // A suggestion is a proposal about a date; it cannot exist without the problem that
        // made it necessary, and it cannot be a day-precision assertion when the cell was
        // only a month.
        DB::statement('ALTER TABLE legacy_import_rows ADD CONSTRAINT legacy_import_rows_date_problem_check
            CHECK ((affiliation_date_problem IS NULL AND affiliation_date_suggestion IS NULL)
                OR affiliation_date_problem IS NOT NULL)');

        // §9.4's arbitration is a closed set; `conflict` is what raises
        // `company_arl_metadata_conflict`.
        DB::statement("ALTER TABLE legacy_import_rows ADD CONSTRAINT legacy_import_rows_arl_evidence_check
            CHECK (arl_evidence IS NULL OR arl_evidence IN ('title', 'header', 'conflict'))");

        // Either both ARL sources are present and were arbitrated, or neither was: a row with
        // one source and no record of which is the silent fallback §9.4 forbids.
        DB::statement('ALTER TABLE legacy_import_rows ADD CONSTRAINT legacy_import_rows_arl_sources_check
            CHECK ((arl_token_title IS NULL AND arl_token_row IS NULL AND arl_evidence IS NULL)
                OR arl_evidence IS NOT NULL)');

        // The permitted risks are levels 1..5, the same shape `arl_risk_class` already has.
        DB::statement('ALTER TABLE legacy_import_rows ADD CONSTRAINT legacy_import_rows_permitted_risks_check
            CHECK (arl_permitted_risks IS NULL
                OR jsonb_typeof(arl_permitted_risks) = \'array\')');

        // `entity_states` is a map of the four entity types to a small object, and every key must
        // be one of them.
        //
        // Written as a function rather than inline, and the two obvious inline forms are both
        // wrong. `<@` on an object tests containment of keys **and** values, so a legitimate
        // `{"EPS": {"token": "SALUD TOTAL", …}}` fails against `["EPS","AFP","CCF","ARL"]` because
        // the value is not in the list. `?&` is the right operator for "all these keys exist",
        // but `?` is a PDO placeholder marker and Laravel's `DB::statement()` turns it into a
        // bound parameter — so the check arrived at PostgreSQL as `$1&`.
        //
        // `IMMUTABLE` matters: a CHECK expression is re-evaluated on every write and PostgreSQL
        // will not use a non-immutable function in one, so a plain `plpgsql` function would be
        // rejected at table creation.
        $allowedTypes = implode(",\n", array_map(
            static fn (SocialSecurityEntityType $type): string => "                    '".$type->value."'",
            SocialSecurityEntityType::cases(),
        ));

        DB::statement(sprintf(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_import_entity_states_valid(states jsonb)
            RETURNS boolean AS $$
            DECLARE
                allowed text[] := ARRAY[
                    'EPS',
                    'AFP',
                    'ARL',
                    'CCF'
                ];
                seen text;
            BEGIN
                IF states IS NULL THEN
                    RETURN true;
                END IF;

                IF jsonb_typeof(states) <> 'object' THEN
                    RETURN false;
                END IF;

                FOR seen IN SELECT jsonb_object_keys(states) LOOP
                    IF NOT (seen = ANY (allowed)) THEN
                        RETURN false;
                    END IF;
                END LOOP;

                RETURN true;
            END;
            $$ LANGUAGE plpgsql IMMUTABLE
        SQL, $allowedTypes));

        DB::statement('ALTER TABLE legacy_import_rows ADD CONSTRAINT legacy_import_rows_entity_states_check
            CHECK (legacy_import_entity_states_valid(entity_states))');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS legacy_import_entity_states_valid(jsonb)');

        Schema::table('legacy_import_rows', function ($table): void {
            $table->dropIndex('legacy_import_rows_source_key_unique');

            $table->dropColumn([
                'source_key',
                'company_verification_digit',
                'company_title_raw',
                'affiliation_date_suggestion',
                'affiliation_date_problem',
                'amount_problem',
                'email_problem',
                'arl_token_title',
                'arl_token_row',
                'arl_evidence',
                'arl_permitted_risks',
                'entity_states',
            ]);
        });
    }
};

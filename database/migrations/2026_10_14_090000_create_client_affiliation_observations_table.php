<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A02: a client's affiliation with the social security system, **where the entity is unknown**.
 *
 * ## What this records, and what it deliberately does not
 *
 * The delivered workbook writes `SI` in the AFP column for 36 rows. That is a real assertion —
 * the person *was* affiliated to a pension fund in that month — and §9.1 has always refused to
 * invent an entity for it. What it could not do was record the assertion itself, so the two
 * honest options were both bad:
 *
 *  - block the batch until somebody named the fund, so a gap in a payroll spreadsheet stops the
 *    whole import; or
 *  - drop the affiliation, so A03 later reads "no AFP that month" for a person who had one.
 *
 * Both are the failure §1 is about. This table is the third option: the affiliation is recorded
 * **as an observation**, with the months it covers and no entity, and the person reading the
 * client's history sees that the fund is missing rather than seeing a hole.
 *
 * ## Why a new table rather than a nullable `social_security_entity_id`
 *
 * `client_affiliations.social_security_entity_id` is `NOT NULL`, and that is right: an
 * `client_affiliations` row exists to hang a real entity's history off, and A03's queries join
 * through that id. Widening it to nullable would push "which fund?" into every one of those
 * queries, and the first place that forgot to handle null would return "no affiliation" — the
 * exact false negative this table exists to prevent.
 *
 * So the two kinds of record stay apart: `client_affiliations` says *which* entity, this table
 * says *that there was one and we were not told which*. No existing query changes, which is why
 * nothing that reads a client's history can start returning a different answer because of this.
 *
 * ## The dates are the same two dates, not a third kind
 *
 * `started_on` / `ended_on` mean exactly what they mean on `client_affiliations`, and the period
 * is `[started_on, ended_on)`. A month the source did not date gives a null start with
 * `unknown` precision, which §8 permits: the observation says when it was seen to be true, not
 * when it began.
 *
 * ## `dedupe_key`
 *
 * Re-running an import over the same source must not double the observations, and the natural
 * identity of one is `(client, type, interval)` — the same key `AffiliationSegment::naturalKey()`
 * builds. It is stored rather than derived because `started_on` is nullable and a unique index
 * over nullable columns does not do what it looks like it does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_affiliation_observations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();

            // Repeated on purpose, for the reason `client_affiliations` repeats it: a CHECK
            // constraint cannot look at another table, and "an AFP observation may not be filed
            // as an EPS one" is a statement worth letting the database reject.
            $table->string('type', 8);

            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();

            /*
             * `unknown` | `month` | `day`, exactly as on `client_affiliations`.
             *
             * The precision is kept beside the date rather than inferred from it, because §8.3's
             * "no mostrar un mes como un día" is the whole point: a segment that opens on the
             * first of a month was *seen* in that month, and a reader that lost the distinction
             * would print a day the source never asserted.
             */
            $table->string('started_on_precision', 16)->default('unknown');
            $table->string('ended_on_precision', 16)->nullable();

            /*
             * The months the observation covers, ascending.
             *
             * Stored rather than derived, because the months are what the source actually
             * asserted and the interval is a reconstruction of them. Two readers that disagree
             * about the interval must still be able to see the same evidence.
             */
            $table->jsonb('months');

            /*
             * Why the entity is missing. One value today — `entity_unknown`, §9.1's bare `SI` —
             * and it is a column rather than a boolean so a second reason can be added without a
             * migration that reinterprets the existing rows.
             */
            $table->string('reason', 32)->default('entity_unknown');

            /* §13's provenance: which import observed this, and which rows of it said so. */
            $table->foreignId('legacy_import_id')
                ->nullable()
                ->constrained('legacy_imports')
                ->nullOnDelete();

            $table->jsonb('source_row_ids')->nullable();

            $table->string('dedupe_key')->unique();

            $table->timestampsTz();
        });

        // The one read every surface needs: "what does this client's file say, and when does it
        // stop saying it". Partial, because the common case is a still-open observation.
        DB::statement(
            'CREATE INDEX client_affiliation_observations_client_type '
            .'ON client_affiliation_observations (client_id, type, started_on)'
        );

        /*
         * §9.5's rule is about `client_affiliations`, and it is NOT restated here.
         *
         * That is deliberate. The rule exists to stop two open EPS rows for one person from
         * being read as two coverages. An observation with no entity is not a coverage: it is a
         * note that one is missing, so two of them carry no meaning that one would not, and
         * forbidding them would delete the second and third month's worth of evidence for no
         * gain.
         */
        DB::statement(
            'ALTER TABLE client_affiliation_observations ADD CONSTRAINT affiliation_observations_dates_check '
            .'CHECK (ended_on IS NULL OR started_on IS NULL OR ended_on >= started_on)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('client_affiliation_observations');
    }
};

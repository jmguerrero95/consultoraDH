<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A02: the historical affiliations of a client with the social security system.
 *
 * Same principle as the company relationship: rows accumulate and are closed, so
 * the system can answer what a client was affiliated to on a given date. When an
 * EPS changes, the old row is closed and a new one opened inside one transaction;
 * the old row is never rewritten.
 *
 * ## The meaning of the two dates
 *
 * The period is `[started_on, ended_on)`, the same convention the company
 * relationships use. `started_on` is the first day the affiliation is effective and
 * `ended_on` the first day it is no longer, so a change of entity writes one date
 * as the end of the old row and the start of the new one: no overlap, no gap. The
 * rule lives in `App\Domain\Shared\EffectivePeriod` and the `activeOn()` scope
 * implements it.
 *
 * The `type` is repeated here even though the entity already carries one. That is
 * deliberate redundancy with a purpose: a CHECK constraint cannot inspect another
 * table, so the type lives here as well in order to make "an ARL affiliation may
 * not point at an EPS entity" a statement the database can reject. The application
 * enforces it too, in both directions, because a constraint that only fires on
 * writes through one code path is not a guarantee.
 *
 * The optional `client_company_assignment_id` links the affiliation to the
 * relationship it was declared under, which is what allows a future import to
 * explain a change of EPS that coincided with a change of employer. It is
 * nullable because historical affiliations may predate the relationship record
 * or may be declared without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_affiliations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('social_security_entity_id')->constrained('social_security_entities')->restrictOnDelete();

            $table->foreignId('client_company_assignment_id')
                ->nullable()
                ->constrained('client_company_assignments')
                ->nullOnDelete();

            $table->string('type', 8);

            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();

            /*
             | The ARL risk level, stored as the integer 1..5.
             |
             | Only meaningful for an ARL. The database rejects a value on any
             | other type, so a risk level can never end up attached to an EPS
             | row by a mistaken request.
             */
            $table->unsignedTinyInteger('arl_risk_class')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // "Which EPS is this client on now?"
            $table->index(['client_id', 'type', 'ended_on'], 'affiliations_client_type_active_index');

            // "How many workers does this ARL have?"
            $table->index(['social_security_entity_id', 'ended_on'], 'affiliations_entity_active_index');

            // "What did this client have on this date?"
            $table->index(['client_id', 'started_on'], 'affiliations_client_period_index');
        });

        DB::statement(
            'ALTER TABLE client_affiliations ADD CONSTRAINT affiliations_type_check '
            ."CHECK (type IN ('EPS', 'AFP', 'ARL', 'CCF'))"
        );

        DB::statement(
            'ALTER TABLE client_affiliations ADD CONSTRAINT affiliations_dates_check '
            .'CHECK (ended_on IS NULL OR (started_on IS NOT NULL AND ended_on >= started_on))'
        );

        /*
         | The risk level is 1..5, and only an ARL may carry one.
         |
         | Both halves in one constraint: a level outside the range is rejected,
         | and a level on a non ARL row is rejected too. Level V means "not
         | classified" and is a legitimate value; NULL means genuinely unknown
         | and is allowed for any type.
         */
        DB::statement(
            'ALTER TABLE client_affiliations ADD CONSTRAINT affiliations_risk_check '
            ."CHECK ((arl_risk_class IS NULL) OR (type = 'ARL' AND arl_risk_class BETWEEN 1 AND 5))"
        );

        /*
         | One open affiliation of each type per client.
         |
         | A partial unique index: a client has at most one current EPS, one
         | current AFP, one current ARL and one current Caja de Compensación.
         | Closed rows are unaffected, so the full history may contain as many
         | EPS affiliations as the client ever had.
         |
         | This is the one place where the database refuses something the
         | application would otherwise have to police with a check-then-insert
         | that two concurrent requests could both pass. It is what makes the
         | overlap warning a guarantee rather than a hope.
         */
        DB::statement(
            'CREATE UNIQUE INDEX affiliations_one_open_per_type_unique '
            .'ON client_affiliations (client_id, type) WHERE ended_on IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('client_affiliations');
    }
};

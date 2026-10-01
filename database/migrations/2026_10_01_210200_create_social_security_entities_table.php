<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A02: the catalogue of social security entities (EPS, AFP, ARL, CCF).
 *
 * This table is deliberately NOT seeded with a Colombian catalogue. A list of
 * every EPS and AFP written from memory would be stale the day it is written and
 * would be indistinguishable from authoritative data to whoever imports into it
 * next. An empty catalogue that an authorised administrator fills from an
 * official source is honest; a plausible catalogue that nobody can vouch for is
 * not.
 *
 * `normalized_name` exists so that "Nueva EPS" and "NUEVA EPS" cannot become two
 * records. It is a generated column, so it cannot drift from the name it is
 * derived from: there is no code path that writes one without the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_security_entities', function (Blueprint $table): void {
            $table->id();

            $table->string('type', 8);
            $table->string('name', 180);

            // Generated, so normalisation can never be skipped or contradicted.
            // STORED so it can be indexed; the expression is PostgreSQL's, so
            // collapse runs of whitespace and lower case it in one place.
            $table->string('normalized_name', 180)->storedAs(
                "lower(regexp_replace(btrim(name), '\\s+', ' ', 'g'))"
            );

            $table->string('code', 40)->nullable();
            $table->string('tax_id', 32)->nullable();

            $table->string('status', 20)->default('active');

            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('code');
        });

        DB::statement(
            'ALTER TABLE social_security_entities ADD CONSTRAINT social_security_entities_type_check '
            ."CHECK (type IN ('EPS', 'AFP', 'ARL', 'CCF'))"
        );

        DB::statement(
            'ALTER TABLE social_security_entities ADD CONSTRAINT social_security_entities_status_check '
            ."CHECK (status IN ('active', 'inactive'))"
        );

        DB::statement(
            'ALTER TABLE social_security_entities ADD CONSTRAINT social_security_entities_name_check '
            .'CHECK (length(btrim(name)) > 0)'
        );

        /*
         | One entity per name per type. The same organisation is a different
         | entity when it appears under two types, and "Nueva EPS" must not
         | become a second record that looks the same in every list.
         |
         | Concurrency safe by construction, which an application level check
         | would not be.
         */
        DB::statement(
            'CREATE UNIQUE INDEX social_security_entities_name_type_unique '
            .'ON social_security_entities (type, normalized_name)'
        );

        // A code identifies one entity across the catalogue.
        DB::statement(
            'CREATE UNIQUE INDEX social_security_entities_code_unique ON social_security_entities (upper(btrim(code))) '
            ."WHERE code IS NOT NULL AND btrim(code) <> ''"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('social_security_entities');
    }
};

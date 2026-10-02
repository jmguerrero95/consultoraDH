<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A02-R1: a catalogue code is not globally unique, and this application has no
 * source that says it is.
 *
 * A02 created `social_security_entities.code` as a unique index across the whole
 * catalogue, with the reasoning that a code identifies one entity among all of
 * the EPS, AFP, ARL and CCF together. That was an assumption, not a fact: A02
 * established no authoritative source for the codes, and reference data imported
 * from one source may well reuse a code across providers, or change one.
 *
 * A constraint invented without a source behind it is worse than no constraint:
 * it refuses data that may be perfectly good, and the importer that eventually
 * arrives will have to weaken it anyway. So the uniqueness goes and an ordinary
 * index stays, which is all the search path needs.
 *
 * Should an official source later establish uniqueness within a type, that is a
 * deliberate decision for the task that imports the data, taken with the source
 * in hand and not in anticipation of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS social_security_entities_code_unique');

        // `code` already has a plain index from the A02 migration; this keeps one
        // in place even on a schema where it is missing.
        DB::statement('CREATE INDEX IF NOT EXISTS social_security_entities_code_index ON social_security_entities (upper(btrim(code)))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS social_security_entities_code_index');

        DB::statement(
            'CREATE UNIQUE INDEX social_security_entities_code_unique ON social_security_entities (upper(btrim(code))) '
            ."WHERE code IS NOT NULL AND btrim(code) <> ''"
        );
    }
};

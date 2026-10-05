<?php

declare(strict_types=1);

use App\Domain\Affiliations\SocialSecurityEntityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A04: approved aliases between a source spelling and a catalogue entity.
 *
 * ## Why this table and not a lookup inside the parser
 *
 * The workbook spells the same EPS a dozen ways: `SALUD TOTAL` and `SALUDTOTAL`,
 * `SURA` and `SURA EPS`, and two spellings close enough to `SANITAS` that only somebody
 * who knows the Colombian health system can say which is meant. §9.2 forbids fuzzy
 * merging: two entities that differ by one letter are two entities until a person says
 * otherwise, because merging them silently attaches a client's history to the wrong
 * company.
 *
 * So the parser produces a **suggestion** and an `unresolved_social_entity` issue, and a
 * person records the answer here. The next workbook that says `SALUDTOTAL` resolves
 * without anyone deciding again.
 *
 * ## `verified_by` is what makes a mapping trustworthy
 *
 * A mapping with nobody behind it is a guess with a lookup table. Requiring the person and
 * the moment means a reader of this table can ask "who decided this, and when", which is
 * the difference between a rule and a habit.
 *
 * ## One mapping per (profile, type, source spelling)
 *
 * The source key is stored normalised — folded, collapsed, accents removed — because the
 * whole point is that a human variation in spelling does not create a second mapping. Two
 * spellings that normalise together are the same question, and the unique index says so.
 *
 * ## Scope is deliberately narrow
 *
 * A mapping applies to one profile. A future importer of the same payroll provider in a
 * different shape may well spell its EPS differently, and inheriting this profile's
 * decisions would be exactly the silent merge §9.2 forbids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_source_mappings', function ($table): void {
            $table->id();

            $table->string('profile', 48);

            /** EPS, AFP, ARL or CCF. */
            $table->string('type', 8);

            /** The source spelling, normalised: folded, accents removed, spaces collapsed. */
            $table->string('source_key', 120);

            $table->foreignId('social_security_entity_id')
                ->constrained('social_security_entities')
                ->restrictOnDelete();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();

            $table->text('note')->nullable();

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE import_source_mappings ADD CONSTRAINT import_source_mappings_type_check
            CHECK (type IN ('.
            implode(',', array_map(
                static fn (SocialSecurityEntityType $type): string => "'{$type->value}'",
                SocialSecurityEntityType::cases(),
            )).'))');

        DB::statement('ALTER TABLE import_source_mappings ADD CONSTRAINT import_source_mappings_profile_check
            CHECK (profile = \'blinden_legacy_monthly_v1\')');

        // A verified mapping names who verified it. NULL is allowed on purpose — a mapping
        // written while reviewing an import is being decided *by* that review, and the
        // person is the importer's actor — but the two are recorded together so a mapping
        // cannot end up looking verified with nobody behind it.
        DB::statement('ALTER TABLE import_source_mappings ADD CONSTRAINT import_source_mappings_verified_check
            CHECK ((verified_at IS NULL AND verified_by IS NULL)
                OR (verified_at IS NOT NULL AND verified_by IS NOT NULL))');

        // The same source spelling cannot be mapped to two entities for one profile and
        // type. This is the constraint that makes "decided once" true.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX import_source_mappings_source_unique
                ON import_source_mappings (profile, type, source_key)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('import_source_mappings');
    }
};

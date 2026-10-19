<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the pension subtypes to `regulatory_class`.
 *
 * ## Why this is needed
 *
 * The workbook has a column called AFP and it contains `COLPENSIONES`. Keeping `type=AFP` is the
 * right compatibility choice — the historical file already uses that column and renaming it would
 * break every import — but the label is an operational grouping, not a regulatory claim.
 *
 * Without a place to record the difference, the two are indistinguishable: a search for private
 * pension fund administrators would return Colpensiones, and `type` would be asserting that a public
 * prima media administrator is a private fund. That is a false statement about a financial
 * institution, recorded in a field other systems read.
 *
 * ## Why these three and no more
 *
 *     afp_privada ............... the private pension fund administrator
 *     administradora_publica_rpm  the public prima media regime
 *     accai ..................... an additional role that does not make an entity an AFP
 *
 * `accai` is in the list because a separate role exists and would otherwise have nowhere honest to
 * go, and precisely because naming it prevents it from being mistaken for the first. An entity
 * named in the Superintendencia Financiera's ACCAI material is not thereby a private pension
 * administrator.
 *
 * No other value is added. A constraint is a list of things somebody has thought about, and adding
 * a value for a case that has not happened is how a constraint stops meaning anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE social_security_entities DROP CONSTRAINT social_security_entities_regulatory_class_check');

        DB::statement(<<<'SQL'
            ALTER TABLE social_security_entities ADD CONSTRAINT social_security_entities_regulatory_class_check
            CHECK (regulatory_class IS NULL OR regulatory_class IN (
                'eps_contributiva',
                'eps_subsidiada',
                'eps_ambos_regimenes',
                'entidad_adaptada',
                'caja_eps_subsidiada',
                'afp_privada',
                'administradora_publica_rpm',
                'accai'
            ))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE social_security_entities DROP CONSTRAINT social_security_entities_regulatory_class_check');

        DB::statement(<<<'SQL'
            ALTER TABLE social_security_entities ADD CONSTRAINT social_security_entities_regulatory_class_check
            CHECK (regulatory_class IS NULL OR regulatory_class IN (
                'eps_contributiva',
                'eps_subsidiada',
                'eps_ambos_regimenes',
                'entidad_adaptada',
                'caja_eps_subsidiada'
            ))
            SQL);
    }
};

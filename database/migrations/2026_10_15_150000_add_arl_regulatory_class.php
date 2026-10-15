<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `arl` to the `regulatory_class` check constraint.
 *
 * ## Why a new value rather than reusing one
 *
 * The existing values are EPS subtypes and pension subtypes. An administradora de riesgos laborales is
 * none of those, and filing it as one would be a false classification about a financial institution.
 *
 * ## What this field is, and is not
 *
 * It records institutional **nature**: this body is an ARL. It records nothing about **authorisation**
 * — whether it may currently write a policy is a different fact, on a different clock, granted by the
 * Ministry of Labour. Collapsing the two would make a catalogue row unable to say both "an ARL" and
 * "wound up in 2019", which is precisely the state Liberty is in.
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
                'accai',
                'arl'
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
                'caja_eps_subsidiada',
                'afp_privada',
                'administradora_publica_rpm',
                'accai'
            ))
            SQL);
    }
};

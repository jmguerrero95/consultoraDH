<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The institutional subtype, which `type` alone cannot express.
 *
 * ## Why a new column rather than a convention on `name`
 *
 * The Ministerio de Salud's own list of 28 authorised EPS (05/06/2025) contains three kinds of
 * body that are all, in a payroll file, something a person is affiliated to in an EPS column:
 *
 *     22  ordinary EPS — EPS, ESS, EPSI and EPS-S codes
 *      4  Cajas de Compensación operating in the subsidised regime — **CCF** codes
 *      2  Entidades Adaptadas al Sistema General de Seguridad Social — **EAS** codes
 *
 * The two extraordinary groups are not ordinary EPS, and saying otherwise is false. The four cajas
 * are the same organisations that appear in the CCF catalogue under those very codes, so filing
 * them as `CCF055` in a row whose `type` is `EPS` would put one code space inside another and make
 * a CCF lookup miss it. The two adaptadas are not EPS at all — they are outside the general regime
 * — and typing them `EPS` asserts an authorisation they do not have.
 *
 * ## What is deliberately not inferred
 *
 * This column records what the source says, on the date the source says it. It never says an entity
 * is still authorised today. A 2025 list cannot testify to 2026, and a row that claimed it could
 * would be trusted for exactly the thing it does not know.
 *
 * Nullable, because a row created by hand in the admin panel has no source document behind it and
 * inventing a class for it would be a fabricated provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE social_security_entities ADD COLUMN regulatory_class varchar(32)');

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

        // Where the class was established, and by what document. Kept beside the class because a
        // bare value is unfalsifiable: a reader cannot tell a class read from the 2025 register
        // from one somebody typed in 2026.
        DB::statement('ALTER TABLE social_security_entities ADD COLUMN regulatory_class_source varchar(255)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE social_security_entities DROP CONSTRAINT social_security_entities_regulatory_class_check');
        DB::statement('ALTER TABLE social_security_entities DROP COLUMN regulatory_class_source');
        DB::statement('ALTER TABLE social_security_entities DROP COLUMN regulatory_class');
    }
};

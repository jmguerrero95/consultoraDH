<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

/**
 * The 42 Cajas de Compensación Familiar of the SSF's official directory.
 *
 * ## The source, and what it does and does not give
 *
 * Superintendencia del Subsidio Familiar, «Directorio de Cajas de Compensación Familiar»,
 * `https://www.ssf.gov.co/directorio2`, read on 15/10/2026. The page returned exactly **42** rows,
 * which matches the regulator's own published count — see {@see SOURCE_EXPECTED}.
 *
 * The directory publishes a **name and a sigla per caja and nothing else**: no code, no NIT. So
 * `code` and `tax_id` are null for all 42, and they are null because the source does not carry
 * them, not because they were looked for and not found. A catalogue that filled those columns from
 * memory would look identical and would be wrong.
 *
 * Note the NIT rule deliberately is **not** inherited from the EPS catalogue. A NIT is whatever the
 * issuing registry documents; imposing the EPS table's nine-digit shape on a different set of
 * entities would reject correct identifiers.
 *
 * ## Three traps in this list, all of them real
 *
 * **A sigla that is not unique.** `COMFAMILIAR` alone is the sigla of **three different cajas** —
 * Cartagena y Bolívar, Nariño and Huila — and the other three in that family carry the sigla
 * qualified (`COMFAMILIAR RISARALDA`, `COMFAMILIAR ATLÁNTICO`, `COMFAMILIAR PUTUMAYO`). Folding
 * those six into one entity would merge three real organisations; treating one `COMFAMILIAR` as
 * authoritative for all three would attribute a Huila affiliation to Nariño. Every row is therefore
 * keyed by its **full name**, and the three bare-sigla rows are flagged `sigla_not_unique`.
 *
 * The same holds for `COMFENALCO`, which is six distinct cajas here — Cartagena, Tolima,
 * Antioquia, Santander, Quindío and Valle del Cauca. There is no generic `COMFENALCO` row, and
 * adding one is exactly the false entity the brief warns about.
 *
 * **A name that identifies nothing.** Eight rows publish the name `Caja de Compensación Familiar`
 * with nothing after it, the sigla carrying all the information. Left as published they would be
 * eight identical names in one table. They are qualified with their sigla and flagged
 * `name_qualified`, so the qualification is visible rather than silently assumed.
 *
 * **A source artefact.** `COMFENALCO VALLE DELAGENTE` is reproduced **verbatim from the page's own
 * HTML** — the trailing `AGENTE` is a text overlap in the published document, not a parsing error.
 * It is recorded exactly as issued and flagged `source_artifact`. The plausible intended sigla is
 * `COMFENALCO VALLE DEL CAUCA`, but this manifest does not write it: the page does not say it, and a
 * corrected value presented as read from the source is a fabricated citation.
 *
 * ## What "vigentes" would mean here, and why it is `unknown`
 *
 * A directory is a snapshot. It says which cajas the regulator lists; it does not attest their
 * authorisation status in October 2026, and this manifest asserts nothing about it.
 */
final class CcfNationalCatalogue
{
    public const SOURCE_AUTHORITY = 'Superintendencia del Subsidio Familiar';

    public const SOURCE_TITLE = 'Directorio de Cajas de Compensación Familiar';

    public const SOURCE_URL = 'https://www.ssf.gov.co/directorio2';

    public const SOURCE_PUBLISHED = 'unknown';

    public const VERIFIED_ON = '2026-10-15';

    /** The regulator's own published count. The directory returned exactly this many. */
    public const SOURCE_EXPECTED = 42;

    /**
     * Deliberately `unknown`, and asserted as such by a test.
     *
     * The directory has no publication date of its own and nothing in it speaks to authorisation
     * today. "The regulator lists it" is what was verified; "it is enabled in October 2026" is not
     * something this source can support.
     */
    public const REGULATORY_STATUS = 'unknown';

    /**
     * @return list<array{registry: int, name: string, sigla: string, code: null, tax_id: null, flags?: list<string>}>
     */
    public static function records(): array
    {
        return [
            ['registry' => 1, 'name' => 'Caja de Compensación Familiar de Barrancabermeja', 'sigla' => 'CAFABA', 'code' => null, 'tax_id' => null],
            ['registry' => 2, 'name' => 'Caja de Compensación Familiar (CAFAM)', 'sigla' => 'CAFAM', 'code' => null, 'tax_id' => null, 'flags' => ['name_qualified']],
            ['registry' => 3, 'name' => 'Caja de Compensación Familiar del Amazonas', 'sigla' => 'CAFAMAZ', 'code' => null, 'tax_id' => null],
            ['registry' => 4, 'name' => 'Caja de Compensación Familiar del Sur del Tolima', 'sigla' => 'CAFASUR', 'code' => null, 'tax_id' => null],
            ['registry' => 5, 'name' => 'Caja de Compensación Familiar (CAJACOPI ATLÁNTICO)', 'sigla' => 'CAJACOPI ATLÁNTICO', 'code' => null, 'tax_id' => null, 'flags' => ['name_qualified']],
            ['registry' => 6, 'name' => 'Caja de Compensación Familiar del Magdalena', 'sigla' => 'CAJAMAG', 'code' => null, 'tax_id' => null],
            ['registry' => 7, 'name' => 'Caja de Compensación Familiar de San Andrés y Providencia', 'sigla' => 'CAJASAI', 'code' => null, 'tax_id' => null],
            ['registry' => 8, 'name' => 'Caja Santandereana de Subsidio Familiar', 'sigla' => 'CAJASAN', 'code' => null, 'tax_id' => null],
            ['registry' => 9, 'name' => 'Caja de Compensación Familiar (COFREM)', 'sigla' => 'COFREM', 'code' => null, 'tax_id' => null, 'flags' => ['name_qualified']],
            ['registry' => 10, 'name' => 'Caja Colombiana de Subsidio Familiar', 'sigla' => 'COLSUBSIDIO', 'code' => null, 'tax_id' => null],
            ['registry' => 11, 'name' => 'Caja de Compensación Familiar de Barranquilla', 'sigla' => 'COMBARRANQUILLA', 'code' => null, 'tax_id' => null],
            ['registry' => 12, 'name' => 'Caja de Compensación Familiar Campesina', 'sigla' => 'COMCAJA', 'code' => null, 'tax_id' => null],
            ['registry' => 13, 'name' => 'Caja de Compensación Familiar de Boyacá', 'sigla' => 'COMFABOY', 'code' => null, 'tax_id' => null],
            ['registry' => 14, 'name' => 'Caja de Compensación Familiar del Caquetá', 'sigla' => 'COMFACA', 'code' => null, 'tax_id' => null],
            ['registry' => 15, 'name' => 'Caja de Compensación Familiar del Casanare', 'sigla' => 'COMFACASANARE', 'code' => null, 'tax_id' => null],
            ['registry' => 16, 'name' => 'Caja de Compensación Familiar del Cauca', 'sigla' => 'COMFACAUCA', 'code' => null, 'tax_id' => null],
            ['registry' => 17, 'name' => 'Caja de Compensación Familiar del Cesar', 'sigla' => 'COMFACESAR', 'code' => null, 'tax_id' => null],
            ['registry' => 18, 'name' => 'Caja de Compensación Familiar del Chocó', 'sigla' => 'COMFACHOCÓ', 'code' => null, 'tax_id' => null],
            ['registry' => 19, 'name' => 'Caja de Compensación Familiar de Córdoba', 'sigla' => 'COMFACOR', 'code' => null, 'tax_id' => null],
            ['registry' => 20, 'name' => 'Caja de Compensación Familiar de Cundinamarca', 'sigla' => 'COMFACUNDI', 'code' => null, 'tax_id' => null],
            ['registry' => 21, 'name' => 'Caja de Compensación Familiar de La Guajira', 'sigla' => 'COMFAGUAJIRA', 'code' => null, 'tax_id' => null],
            ['registry' => 22, 'name' => 'Caja de Compensación Familiar de Antioquia', 'sigla' => 'COMFAMA', 'code' => null, 'tax_id' => null],
            ['registry' => 23, 'name' => 'Caja de Compensación Familiar de Cartagena y Bolívar', 'sigla' => 'COMFAMILIAR', 'code' => null, 'tax_id' => null, 'flags' => ['sigla_not_unique']],
            ['registry' => 24, 'name' => 'Caja de Compensación Familiar de Nariño', 'sigla' => 'COMFAMILIAR', 'code' => null, 'tax_id' => null, 'flags' => ['sigla_not_unique']],
            ['registry' => 25, 'name' => 'Caja de Compensación Familiar del Huila', 'sigla' => 'COMFAMILIAR', 'code' => null, 'tax_id' => null, 'flags' => ['sigla_not_unique']],
            ['registry' => 26, 'name' => 'Caja de Compensación Familiar (COMFAMILIAR ATLÁNTICO)', 'sigla' => 'COMFAMILIAR ATLÁNTICO', 'code' => null, 'tax_id' => null, 'flags' => ['name_qualified']],
            ['registry' => 27, 'name' => 'Caja de Compensación Familiar del Putumayo', 'sigla' => 'COMFAMILIAR PUTUMAYO', 'code' => null, 'tax_id' => null],
            ['registry' => 28, 'name' => 'Caja de Compensación Familiar de Risaralda', 'sigla' => 'COMFAMILIAR RISARALDA', 'code' => null, 'tax_id' => null],
            ['registry' => 29, 'name' => 'Caja de Compensación Familiar del Valle del Cauca Comfamiliar Andi', 'sigla' => 'COMFANDI', 'code' => null, 'tax_id' => null],
            ['registry' => 30, 'name' => 'Caja de Compensación Familiar del Norte de Santander', 'sigla' => 'COMFANORTE', 'code' => null, 'tax_id' => null],
            ['registry' => 31, 'name' => 'Caja de Compensación Familiar del Oriente Colombiano', 'sigla' => 'COMFAORIENTE', 'code' => null, 'tax_id' => null],
            ['registry' => 32, 'name' => 'Caja de Compensación Familiar de Sucre', 'sigla' => 'COMFASUCRE', 'code' => null, 'tax_id' => null],
            ['registry' => 33, 'name' => 'Caja de Compensación Familiar del Tolima', 'sigla' => 'COMFATOLIMA', 'code' => null, 'tax_id' => null],
            ['registry' => 34, 'name' => 'Caja de Compensación Familiar de Fenalco del Tolima', 'sigla' => 'COMFENALCO', 'code' => null, 'tax_id' => null],
            ['registry' => 35, 'name' => 'Caja de Compensación Familiar (COMFENALCO ANTIOQUIA)', 'sigla' => 'COMFENALCO ANTIOQUIA', 'code' => null, 'tax_id' => null, 'flags' => ['name_qualified']],
            ['registry' => 36, 'name' => 'Caja de Compensación Familiar de Fenalco – ANDI', 'sigla' => 'COMFENALCO CARTAGENA', 'code' => null, 'tax_id' => null],
            ['registry' => 37, 'name' => 'Caja de Compensación Familiar de Fenalco Quindío', 'sigla' => 'COMFENALCO QUINDÍO', 'code' => null, 'tax_id' => null],
            ['registry' => 38, 'name' => 'Caja de Compensación Familiar (COMFENALCO SANTANDER)', 'sigla' => 'COMFENALCO SANTANDER', 'code' => null, 'tax_id' => null, 'flags' => ['name_qualified']],
            ['registry' => 39, 'name' => 'Caja de Compensación Familiar del Valle del Cauca', 'sigla' => 'COMFENALCO VALLE DELAGENTE', 'code' => null, 'tax_id' => null, 'flags' => ['source_artifact']],
            ['registry' => 40, 'name' => 'Caja de Compensación Familiar de Arauca', 'sigla' => 'COMFIAR', 'code' => null, 'tax_id' => null],
            ['registry' => 41, 'name' => 'Caja de Compensación Familiar (COMPENSAR)', 'sigla' => 'COMPENSAR', 'code' => null, 'tax_id' => null, 'flags' => ['name_qualified']],
            ['registry' => 42, 'name' => 'Caja de Compensación Familiar (CONFA)', 'sigla' => 'CONFA', 'code' => null, 'tax_id' => null, 'flags' => ['name_qualified']],
        ];
    }

    /**
     * Siglas that more than one caja answers to.
     *
     * Returned rather than resolved, because the only correct answer is that they cannot be.
     *
     * @return list<string>
     */
    public static function ambiguousSiglas(): array
    {
        $seen = [];

        foreach (self::records() as $record) {
            $seen[$record['sigla']][] = $record['name'];
        }

        $ambiguous = [];

        foreach ($seen as $sigla => $names) {
            if (count($names) > 1) {
                $ambiguous[] = $sigla;
            }
        }

        return $ambiguous;
    }

    /** @return array<string, int> */
    public static function flagCounts(): array
    {
        $counts = [];

        foreach (self::records() as $record) {
            foreach ($record['flags'] ?? [] as $flag) {
                $counts[$flag] = ($counts[$flag] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /** @return array<string, mixed> */
    public static function evidence(): array
    {
        return [
            'authority' => self::SOURCE_AUTHORITY,
            'title' => self::SOURCE_TITLE,
            'url' => self::SOURCE_URL,
            'published' => self::SOURCE_PUBLISHED,
            'verified_on' => self::VERIFIED_ON,
            'regulatory_status' => self::REGULATORY_STATUS,
            'expected' => self::SOURCE_EXPECTED,
        ];
    }
}

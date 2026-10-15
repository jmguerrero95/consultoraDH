<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

/**
 * The ten Administradoras de Riesgos Laborales listed by the Ministry of Health.
 *
 * ## The source, and what it does not prove
 *
 * Ministry of Health and Social Protection, «Aseguramiento en Riesgos Laborales»,
 * `https://www.minsalud.gov.co/sites/rid/Lists/BibliotecaDigital/RIDE/VP/DOA/RL/Aseguramiento%20en%20riesgos%20laborales.pdf`.
 * Its table lists ten ARL with a legal name, a website and a contact number each, read verbatim.
 *
 * **This document is historical.** It is a directory, not a certification, and a directory is a
 * snapshot: it cannot say which of the ten is authorised to write today. So every record carries a
 * {@see EVIDENCE_DIRECTORY} status and none claims current authorisation. The brief's own warning is
 * the reason: Liberty is in this table, and the 2025 FRL sectoral study records that it left the ARL
 * market in 2019. A list that still shows it says nothing about 2019, let alone 2026.
 *
 * The 2025 FRL sectoral PDF is the better source on operating history and it **is** reachable, but its
 * tables are drawn with an embedded font whose glyphs do not map back to text, so its figures could
 * not be read. That limitation is recorded rather than worked around, and it is why
 * {@see $HISTORICAL_EXIT} exists: the exit is carried as the brief states it, flagged as coming from
 * that document, and nothing is inferred from a silent absence.
 *
 * ## What is deliberately absent
 *
 * **No NIT.** This document publishes none for these entities, so every `tax_id` is null. The three
 * occurrences of «NIT» elsewhere in the file do not cover this table. Inventing ten NITs from memory
 * would be the single largest fabrication this catalogue could contain.
 *
 * **No regulatory code.** An ARL's authorisation to write risks is granted by the Ministry of Labour
 * and is not published in a form this source carries, so `code` is null throughout. An EPS's SFC code
 * and an ARL's authorisation code are different instruments in different namespaces; putting one in
 * the other's field is exactly the conflation the brief warns against.
 */
final class ArlNationalCatalogue
{
    public const SOURCE_AUTHORITY = 'Ministerio de Salud y Protección Social';

    public const SOURCE_TITLE = 'Aseguramiento en Riesgos Laborales';

    public const SOURCE_URL = 'https://www.minsalud.gov.co/sites/rid/Lists/BibliotecaDigital/RIDE/VP/DOA/RL/Aseguramiento%20en%20riesgos%20laborales.pdf';

    /** The document carries no publication date of its own. */
    public const SOURCE_PUBLISHED = 'unknown';

    public const VERIFIED_ON = '2026-10-15';

    /**
     * What being in this table means, and what it does not.
     *
     * Read as a plain sentence on purpose: someone skimming a catalogue should not be able to
     * mistake it for a current authorisation. `regulatory_class` expresses institutional nature and
     * is NOT reused for authorisation — that was the explicit instruction, and it is right: a body can
     * be an ARL and still be wound up, which is Liberty here.
     */
    public const EVIDENCE_DIRECTORY = 'listed_in_official_directory_as_of_source:presente en el directorio oficial del Ministerio de Salud. Es la condición que la fuente acredita; no es una certificación de habilitación vigente.';

    /** The sectoral study that records the exit, and the limits of what was read from it. */
    public const HISTORICAL_EXIT_SOURCE = 'https://www.fondoriesgoslaborales.gov.co/wp-content/uploads/2025/12/Riesgos-Laborales_Version-final.07.07.25.pdf';

    /**
     * The ten, in the source's own order.
     *
     * @return list<array{registry: int, name: string, sigla: string, code: null, tax_id: null, evidence_status: string, flags: list<string>}>
     */
    public static function records(): array
    {
        return [
            ['registry' => 1, 'name' => 'La Equidad', 'sigla' => 'LA EQUIDAD', 'code' => null, 'tax_id' => null, 'evidence_status' => self::EVIDENCE_DIRECTORY, 'flags' => []],
            ['registry' => 2, 'name' => 'Sura - Cia. Suramericana de Seguros de Vida', 'sigla' => 'SURA', 'code' => null, 'tax_id' => null, 'evidence_status' => self::EVIDENCE_DIRECTORY, 'flags' => []],
            ['registry' => 3, 'name' => 'ARL Positiva', 'sigla' => 'POSITIVA', 'code' => null, 'tax_id' => null, 'evidence_status' => self::EVIDENCE_DIRECTORY, 'flags' => []],
            ['registry' => 4, 'name' => 'Seguros Bolívar S. A.', 'sigla' => 'BOLIVAR', 'code' => null, 'tax_id' => null, 'evidence_status' => self::EVIDENCE_DIRECTORY, 'flags' => []],
            ['registry' => 5, 'name' => 'Seguros de Vida Aurora S. A.', 'sigla' => 'AURORA', 'code' => null, 'tax_id' => null, 'evidence_status' => self::EVIDENCE_DIRECTORY, 'flags' => []],
            ['registry' => 6, 'name' => 'Liberty Seguros de Vida', 'sigla' => 'LIBERTY', 'code' => null, 'tax_id' => null, 'evidence_status' => self::EVIDENCE_DIRECTORY, 'flags' => ['left_the_market']],
            ['registry' => 7, 'name' => 'Mapfre Colombia Vida Seguros S. A.', 'sigla' => 'MAPFRE', 'code' => null, 'tax_id' => null, 'evidence_status' => self::EVIDENCE_DIRECTORY, 'flags' => []],
            ['registry' => 8, 'name' => 'Riesgos Laborales Colmena', 'sigla' => 'COLMENA', 'code' => null, 'tax_id' => null, 'evidence_status' => self::EVIDENCE_DIRECTORY, 'flags' => []],
            ['registry' => 9, 'name' => 'Seguros de Vida Alfa S.A.', 'sigla' => 'ALFA', 'code' => null, 'tax_id' => null, 'evidence_status' => self::EVIDENCE_DIRECTORY, 'flags' => []],
            ['registry' => 10, 'name' => 'Seguros de Vida Colpatria S.A.', 'sigla' => 'AXA COLPATRIA', 'code' => null, 'tax_id' => null, 'evidence_status' => self::EVIDENCE_DIRECTORY, 'flags' => ['axa_brand_not_in_source']],
        ];
    }

    /**
     * What the sectoral study says about an entity that left the market.
     *
     * Carried as an explicit claim with its own source, not deduced from the entity's absence from a
     * later list. "It is not in the 2025 directory" would be an argument from silence; the study says
     * it exited in 2019, and that is a different and much stronger statement.
     */
    public const HISTORICAL_EXIT = [
        'Liberty Seguros de Vida' => 'El estudio sectorial de Riesgos Laborales de 2025 registra que Liberty '
            .'salió del mercado de ARL en 2019. Sigue apareciendo en el directorio histórico del Ministerio '
            .'de Salud, lo que ilustra por qué un directorio no acredita habilitación.',
    ];

    /** @return list<string> */
    public static function flaggedHistorical(): array
    {
        $names = [];

        foreach (self::records() as $record) {
            if (in_array('left_the_market', $record['flags'], true)) {
                $names[] = $record['name'];
            }
        }

        return $names;
    }

    /**
     * Siglas the workbook writes that are **not** a bare ARL name.
     *
     * `POSITIVA CC` and `LA EQUIDAD COMFENALCO` are the workbook's own way of writing an employer with
     * its administrator, not the administrator's name. They are candidates for review, and this list
     * exists so nobody proposes them silently.
     *
     * @return array<string, string> token => what is still unknown about it
     */
    public static function ambiguousTokens(): array
    {
        return [
            'POSITIVA CC' => 'El sufijo «CC» no está explicado por ninguna fuente. Puede designar cobertura, '
                .'o el código de una company, y no se resuelve sin confirmación.',
            'POSITIVA COMFENALCO' => 'Pareja de administradora y empleador escrita en una celda. No es el '
                .'nombre de una ARL.',
            'LA EQUIDAD COMFENALCO' => 'Pareja de administradora y empleador escrita en una celda. No es el '
                .'nombre de una ARL.',
        ];
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
            'expected' => self::SOURCE_EXPECTED,
        ];
    }

    /** The count this source publishes. */
    public const SOURCE_EXPECTED = 10;
}

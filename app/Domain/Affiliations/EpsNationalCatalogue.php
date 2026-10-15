<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

/**
 * The 28 authorised EPS of the Ministry of Health's list of 05/06/2025.
 *
 * ## The source, and what it does not prove
 *
 * Ministry of Health and Social Protection, «EPS vigentes del régimen contributivo y subsidiado
 * SGSSS», published **05/06/2025**. Every entry below was read out of that document's own table:
 * its registration number, the name it prints, both of its codes, its NIT and its regime.
 *
 * "Vigentes" is the Ministry's word on its own publication date. It is **not** a statement about
 * October 2026. A separate later source is needed for that and this manifest does not pretend to
 * have one; {@see REGULATORY_STATUS} records the limit rather than resolving it.
 *
 * ## The three kinds of body in one table
 *
 *     22  ordinary EPS        EPS / ESS / EPSI / EPS-S codes
 *      4  Cajas de Compensación in the subsidised regime   **CCF** codes
 *      2  Entidades Adaptadas to the SGSSS               **EAS** codes
 *
 * All 28 appear in a payroll file as an EPS affiliation, which is why they share `type`. They do not
 * share an institutional nature, and saying they do would be false: a caja is the same organisation
 * that appears in the CCF catalogue under the very code printed here, and an entidad adaptada is
 * not an EPS at all — it sits outside the general regime.
 *
 * So each entry carries a `regulatory_class`. It is the subtype, not a ranking, and nothing reads
 * it to decide whether a person may be affiliated: `type` decides that.
 *
 * ## Why the codes are kept apart
 *
 * The document prints two code columns: the entity's own, and a "código de movilidad". Both are
 * reproduced, in their own fields. Filing the mobility code in `code` would make two different
 * EPS collide on one column, and the EPS codes overlap the CCF and EAS spaces by construction.
 *
 * ## Why no entity is created twice
 *
 * #1 COOSALUD is one EPS with three codes — `ESS024`, `EPS042` and `EPSS42` — because it operates
 * under both regimes. That is one row with its primary code and the rest as aliases. Three rows
 * would put the same NIT in the catalogue three times and make every affiliation to it ambiguous.
 */
final class EpsNationalCatalogue
{
    public const SOURCE_AUTHORITY = 'Ministerio de Salud y Protección Social';

    public const SOURCE_TITLE = 'EPS vigentes del régimen contributivo y subsidiado SGSSS';

    public const SOURCE_URL = 'https://www.minsalud.gov.co/sites/rid/Lists/BibliotecaDigital/RIDE/VP/DOA/listado-eps-por-regimen.pdf';

    /** The date the document carries, not the date this file was written. */
    public const SOURCE_PUBLISHED = '2025-06-05';

    /**
     * What is known about authorisation *today*.
     *
     * Deliberately `unknown`. The list is 16 months old at the time of writing and the brief is
     * explicit that a historical source does not demonstrate current standing. A manifest that
     * said "active" would be asserting something nobody checked; one that says "unknown" is
     * accurate and can be revised when somebody does check.
     */
    public const REGULATORY_STATUS = 'unknown';

    /** The date somebody read the source. Distinct from the source's own date, always. */
    public const VERIFIED_ON = '2026-10-15';

    /**
     * All 28, in the document's own order.
     *
     * `code` is the entity's primary code as printed. `aliases` carries the remaining codes the
     * document gives, including every "código de movilidad" — they identify the same entity and
     * are what a workbook column may actually contain.
     *
     * @return list<array{
     *     registry: int, name: string, regime: string, regulatory_class: string,
     *     code: string|null, mobility_codes: list<string>, tax_id: string
     * }>
     */
    public static function records(): array
    {
        return [
            ['registry' => 1, 'name' => 'COOSALUD EPS-S', 'regime' => 'ambos_regimenes', 'regulatory_class' => 'eps_ambos_regimenes', 'code' => 'ESS024', 'mobility_codes' => ['EPS042', 'EPSS42', 'ESSC24'], 'tax_id' => '900226715'],
            ['registry' => 2, 'name' => 'NUEVA EPS', 'regime' => 'ambos_regimenes', 'regulatory_class' => 'eps_ambos_regimenes', 'code' => 'EPS037', 'mobility_codes' => ['EPSS41', 'EPSS37', 'EPS041'], 'tax_id' => '900156264'],
            ['registry' => 3, 'name' => 'MUTUAL SER', 'regime' => 'ambos_regimenes', 'regulatory_class' => 'eps_ambos_regimenes', 'code' => 'ESS207', 'mobility_codes' => ['EPS048', 'ESSC07', 'EPSS48'], 'tax_id' => '806008394'],
            ['registry' => 4, 'name' => 'SALUD MIA', 'regime' => 'ambos_regimenes', 'regulatory_class' => 'eps_ambos_regimenes', 'code' => 'EPS046', 'mobility_codes' => ['EPSS46'], 'tax_id' => '900914254'],
            ['registry' => 5, 'name' => 'ALIANSALUD EPS', 'regime' => 'contributivo', 'regulatory_class' => 'eps_contributiva', 'code' => 'EPS001', 'mobility_codes' => ['EPSS01'], 'tax_id' => '830113831'],
            ['registry' => 6, 'name' => 'SALUD TOTAL EPS S.A.', 'regime' => 'contributivo', 'regulatory_class' => 'eps_contributiva', 'code' => 'EPS002', 'mobility_codes' => ['EPSS02'], 'tax_id' => '800130907'],
            ['registry' => 7, 'name' => 'EPS SANITAS', 'regime' => 'contributivo', 'regulatory_class' => 'eps_contributiva', 'code' => 'EPS005', 'mobility_codes' => ['EPSS05'], 'tax_id' => '800251440'],
            ['registry' => 8, 'name' => 'EPS SURA', 'regime' => 'contributivo', 'regulatory_class' => 'eps_contributiva', 'code' => 'EPS010', 'mobility_codes' => ['EPSS10'], 'tax_id' => '800088702'],
            ['registry' => 9, 'name' => 'FAMISANAR', 'regime' => 'contributivo', 'regulatory_class' => 'eps_contributiva', 'code' => 'EPS017', 'mobility_codes' => ['EPSS17'], 'tax_id' => '830003564'],
            ['registry' => 10, 'name' => 'SERVICIO OCCIDENTAL DE SALUD EPS SOS', 'regime' => 'contributivo', 'regulatory_class' => 'eps_contributiva', 'code' => 'EPS018', 'mobility_codes' => ['EPSS18'], 'tax_id' => '805001157'],
            ['registry' => 11, 'name' => 'COMFENALCO VALLE', 'regime' => 'contributivo', 'regulatory_class' => 'eps_contributiva', 'code' => 'EPS012', 'mobility_codes' => ['EPSS12'], 'tax_id' => '890303093'],
            ['registry' => 12, 'name' => 'COMPENSAR EPS', 'regime' => 'contributivo', 'regulatory_class' => 'eps_contributiva', 'code' => 'EPS008', 'mobility_codes' => ['EPSS08'], 'tax_id' => '860066942'],
            ['registry' => 13, 'name' => 'EPM - EMPRESAS PUBLICAS DE MEDELLIN', 'regime' => 'contributivo', 'regulatory_class' => 'entidad_adaptada', 'code' => 'EAS016', 'mobility_codes' => [], 'tax_id' => '890904996'],
            ['registry' => 14, 'name' => 'FONDO DE PASIVO SOCIAL DE FERROCARRILES NACIONALES DE COLOMBIA', 'regime' => 'contributivo', 'regulatory_class' => 'entidad_adaptada', 'code' => 'EAS027', 'mobility_codes' => [], 'tax_id' => '800112806'],
            ['registry' => 15, 'name' => 'CAJACOPI ATLANTICO', 'regime' => 'subsidiado', 'regulatory_class' => 'caja_eps_subsidiada', 'code' => 'CCF055', 'mobility_codes' => ['CCFC55'], 'tax_id' => '890102044'],
            ['registry' => 16, 'name' => 'CAPRESOCA', 'regime' => 'subsidiado', 'regulatory_class' => 'eps_subsidiada', 'code' => 'EPS025', 'mobility_codes' => ['EPSC25'], 'tax_id' => '891856000'],
            ['registry' => 17, 'name' => 'COMFACHOCO', 'regime' => 'subsidiado', 'regulatory_class' => 'caja_eps_subsidiada', 'code' => 'CCF102', 'mobility_codes' => ['CCFC20'], 'tax_id' => '891600091'],
            ['registry' => 18, 'name' => 'COMFAORIENTE', 'regime' => 'subsidiado', 'regulatory_class' => 'caja_eps_subsidiada', 'code' => 'CCF050', 'mobility_codes' => ['CCFC50'], 'tax_id' => '890500675'],
            ['registry' => 19, 'name' => 'EPS FAMILIAR DE COLOMBIA', 'regime' => 'subsidiado', 'regulatory_class' => 'caja_eps_subsidiada', 'code' => 'CCF033', 'mobility_codes' => ['CCFC33'], 'tax_id' => '901543761'],
            ['registry' => 20, 'name' => 'ASMET SALUD', 'regime' => 'subsidiado', 'regulatory_class' => 'eps_subsidiada', 'code' => 'ESS062', 'mobility_codes' => ['ESSC62'], 'tax_id' => '900935126'],
            ['registry' => 21, 'name' => 'EMSSANAR E.S.S.', 'regime' => 'subsidiado', 'regulatory_class' => 'eps_subsidiada', 'code' => 'ESS118', 'mobility_codes' => ['ESSC18'], 'tax_id' => '901021565'],
            ['registry' => 22, 'name' => 'CAPITAL SALUD EPS-S', 'regime' => 'subsidiado', 'regulatory_class' => 'eps_subsidiada', 'code' => 'EPSS34', 'mobility_codes' => ['EPSC34'], 'tax_id' => '900298372'],
            ['registry' => 23, 'name' => 'SAVIA SALUD EPS', 'regime' => 'subsidiado', 'regulatory_class' => 'eps_subsidiada', 'code' => 'EPSS40', 'mobility_codes' => ['EPS040'], 'tax_id' => '900604350'],
            ['registry' => 24, 'name' => 'DUSAKAWI EPSI', 'regime' => 'subsidiado', 'regulatory_class' => 'eps_subsidiada', 'code' => 'EPSI01', 'mobility_codes' => ['EPSIC1'], 'tax_id' => '824001398'],
            ['registry' => 25, 'name' => 'ASOCIACION INDIGENA DEL CAUCA EPSI', 'regime' => 'subsidiado', 'regulatory_class' => 'eps_subsidiada', 'code' => 'EPSI03', 'mobility_codes' => ['EPSIC3'], 'tax_id' => '817001773'],
            ['registry' => 26, 'name' => 'ANAS WAYUU EPSI', 'regime' => 'subsidiado', 'regulatory_class' => 'eps_subsidiada', 'code' => 'EPSI04', 'mobility_codes' => ['EPSIC4'], 'tax_id' => '839000495'],
            ['registry' => 27, 'name' => 'MALLAMAS EPSI', 'regime' => 'subsidiado', 'regulatory_class' => 'eps_subsidiada', 'code' => 'EPSI05', 'mobility_codes' => ['EPSIC5'], 'tax_id' => '837000084'],
            ['registry' => 28, 'name' => 'PIJAOS SALUD EPSI', 'regime' => 'subsidiado', 'regulatory_class' => 'eps_subsidiada', 'code' => 'EPSI06', 'mobility_codes' => ['EPSIC6'], 'tax_id' => '809008362'],
        ];
    }

    /** @return array<string, int> */
    public static function countsByRegulatoryClass(): array
    {
        $counts = [];

        foreach (self::records() as $record) {
            $counts[$record['regulatory_class']] = ($counts[$record['regulatory_class']] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /** @return array<string, int> */
    public static function countsByRegime(): array
    {
        $counts = [];

        foreach (self::records() as $record) {
            $counts[$record['regime']] = ($counts[$record['regime']] ?? 0) + 1;
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
        ];
    }
}

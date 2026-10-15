<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

/**
 * The pension administrators, and why this file is empty.
 *
 * ## The rule this catalogue exists to encode
 *
 * The workbook has a column called AFP and it contains `COLPENSIONES`. Keeping `type=AFP` is the
 * right compatibility choice — renaming a column the historical file already uses would break every
 * import — but the label must not be read as a regulatory claim, because the column holds at least
 * two different kinds of institution:
 *
 *     afp_privada .............. a private pension fund administrator
 *     administradora_publica_rpm  Colpensiones: the public prima media regime, not a fund
 *     accai ..................... an additional role, which does **not** make an entity an AFP
 *
 * That last one matters. The Superintendencia Financiera's material on ACCAI names entities that
 * are not private pension administrators, and an entity appearing in that material is not thereby an
 * AFP. Conflating the three would file a public administrator or an ACCAI role as a private fund.
 *
 * ## Why `records()` is empty
 *
 * Both Superintendencia Financiera URLs return **HTTP 403** to an ordinary fetch, and that registry
 * renders its results in JavaScript. Colpensiones' own site publishes no NIT, legal name or
 * regulatory code on the pages reachable here.
 *
 * So nothing in this file is verified, and {@see records()} returns nothing rather than a list of
 * names that would look identical in the catalogue and be wrong. The candidates are named in
 * {@see unverifiedCandidates()} with the reason each is unverified, so the gap is visible and
 * closeable rather than silent.
 *
 * A code is not a single value. These entities are identified by a *type* code and a *particular*
 * code, and they are different namespaces: putting a particular code into a single `code` column
 * would collide across issuers and mean something different for each. Until a source supplies both
 * halves with their namespace, `code` stays null rather than half-filled.
 *
 * ## What must never happen
 *
 * `PORVENR` is a typo of `PORVENIR` and is a **proposed alias**, not a fifth administrator. It is
 * listed in {@see proposedAliases()} and nothing else, and an alias needs the same explicit
 * approval as any other mapping before it resolves anything.
 */
final class PensionNationalCatalogue
{
    /**
     * The subtype values this column may carry for a pension row.
     *
     * Named here rather than inlined so the migration's constraint, the loader and the tests all
     * read the same list.
     *
     * @var array<string, string>
     */
    /** When somebody read the sources. Distinct from anything a source says about itself. */
    public const VERIFIED_ON = '2026-10-15';

    public const SUBTYPE_PRIVATE_AFP = 'afp_privada';

    public const SUBTYPE_PUBLIC_RPM = 'administradora_publica_rpm';

    public const SUBTYPE_ACCAI = 'accai';

    /** The SIMEV entity registry. The individual detail page answered HTTP 200. */
    public const SFC_DETAIL_PROTECCION = 'https://www.superfinanciera.gov.co/Superfinanciera-Simev/faces/generic/FinancialInstitutionSimev.xhtml?id=8783';

    /** The indexed registry page. Answers HTTP 403 to a plain fetch; its data was read from the index. */
    public const SFC_REGISTRY = 'https://www.superfinanciera.gov.co/SIMEV2/rnamv/resultadobusqueda';

    /** Colpensiones' own notice, which publishes the legal name and the NIT. Answers HTTP 200. */
    public const COLPENSIONES_SOURCE = 'https://www.colpensiones.gov.co/publicaciones/661/aviso-de-privacidad/';

    /**
     * What "verified status" means for each record, and what it does **not** mean.
     *
     * The SIMEV record for Protección carries `Estado RNVEI: Activa-Vigente (VIG)` and
     * `Calidad Inscrito: EMISOR E INTERMEDIARIO DEL MERCADO DE VALORES`. Both are real and both are
     * about the **securities market**: registration as an issuer is not a pension authorisation, and
     * a registry status carries the date of the registry, not the standing of the institution today.
     *
     * So no record is recorded as "authorised as of now". Each says what its source says, and the
     * distinction is named so it cannot be read as more than it is.
     */
    public const STATUS_REGISTRY = 'registry_status_as_published:SIMEV RNVEI «Activa-Vigente (VIG)» y «EMISOR E INTERMEDIARIO DEL MERCADO DE VALORES». Es estado registral y del mercado de valores, no autorización pensional vigente.';

    public const STATUS_FROM_SFC_INDEX = 'registry_entry_as_published:presente en el índice del registro SFC el 15/10/2026. No es una certificación de autorización vigente.';

    public const STATUS_PUBLISHED_SELF = 'published_by_the_entity:la entidad publica su nombre y NIT. No se consultó un registro regulatorio externo.';

    public const SUBTYPES = [
        'afp_privada' => 'Administradora privada de fondos de pensiones.',
        'administradora_publica_rpm' => 'Administradora del régimen público de prima media. No es un fondo privado.',
        'accai' => 'Administradora de cuentas de ahorro individual. Es un rol adicional y no convierte a la entidad en AFP.',
    ];

    /**
     * The five institutional records.
     *
     * Four private fund administrators and one public prima media administrator. The `type` column
     * is `AFP` for all five because the workbook has an AFP column and renaming it would break every
     * historical import — but {@see $records}[].`regulatory_class` is what says which kind of
     * institution each one actually is, and the two are not the same claim.
     *
     * @return list<array{
     *     registry: int, name: string, sigla: string, tax_id: string,
     *     regulatory_class: string, sfc: ?array{namespace: string, type_code: string, entity_code: string},
     *     roles: list<string>, verified_on: string, regulatory_status: string, source_url: string
     * }>
     */
    public static function records(): array
    {
        return [
            [
                'registry' => 1,
                // The legal name, verbatim, including the ACCAI clause. It is the ACCAI role inside
                // the name that makes this record the clearest proof that the role and the AFP
                // identity are two separate things about one institution.
                'name' => 'ADMINISTRADORA DE FONDOS DE PENSIONES, CESANTIA Y DEL COMPONENTE COMPLEMENTARIO DE AHORRO INDIVIDUAL PROTECCION S.A.',
                'sigla' => 'PROTECCION',
                'tax_id' => '8001381881',
                'regulatory_class' => self::SUBTYPE_PRIVATE_AFP,
                'sfc' => ['namespace' => 'RNVEI', 'type_code' => '023', 'entity_code' => '002'],
                // Read from the SIMEV record, not inferred from the name.
                'roles' => ['afp', 'accai'],
                'verified_on' => self::VERIFIED_ON,
                'regulatory_status' => self::STATUS_REGISTRY,
                'source_url' => self::SFC_DETAIL_PROTECCION,
            ],
            [
                'registry' => 2,
                'name' => 'Administradora de Fondos de Pérsiones y Cesantías Porvenir S.A.',
                'sigla' => 'PORVENIR',
                'tax_id' => '8001443313',
                'regulatory_class' => self::SUBTYPE_PRIVATE_AFP,
                'sfc' => ['namespace' => 'RNVEI', 'type_code' => '023', 'entity_code' => '003'],
                'roles' => ['afp'],
                'verified_on' => self::VERIFIED_ON,
                'regulatory_status' => self::STATUS_FROM_SFC_INDEX,
                'source_url' => self::SFC_REGISTRY,
            ],
            [
                'registry' => 3,
                'name' => 'Skandia Administradora de Fondos de Pensiones y Cesantías S.A.',
                'sigla' => 'SKANDIA',
                'tax_id' => '8001485142',
                'regulatory_class' => self::SUBTYPE_PRIVATE_AFP,
                'sfc' => ['namespace' => 'RNVEI', 'type_code' => '023', 'entity_code' => '009'],
                'roles' => ['afp'],
                'verified_on' => self::VERIFIED_ON,
                'regulatory_status' => self::STATUS_FROM_SFC_INDEX,
                'source_url' => self::SFC_REGISTRY,
            ],
            [
                'registry' => 4,
                'name' => 'Administradora de Fondos de Pensiones y Cesantías Colfondos S.A.',
                'sigla' => 'COLFONDOS',
                'tax_id' => '8001494962',
                'regulatory_class' => self::SUBTYPE_PRIVATE_AFP,
                'sfc' => ['namespace' => 'RNVEI', 'type_code' => '023', 'entity_code' => '010'],
                'roles' => ['afp'],
                'verified_on' => self::VERIFIED_ON,
                'regulatory_status' => self::STATUS_FROM_SFC_INDEX,
                'source_url' => self::SFC_REGISTRY,
            ],
            [
                'registry' => 5,
                // No SFC code, and deliberately so: the RNVEI namespace codes an institution
                // supervised as a financial entity, and Colpensiones is not one. Forcing
                // 900.336.004-7 into that namespace would assert a supervision it does not have,
                // and inventing a fourth kind of code would be worse.
                'name' => 'Administradora Colombiana de Pensiones',
                'sigla' => 'COLPENSIONES',
                'tax_id' => '9003360047',
                'regulatory_class' => self::SUBTYPE_PUBLIC_RPM,
                'sfc' => null,
                'roles' => ['administradora_publica_rpm'],
                'verified_on' => self::VERIFIED_ON,
                'regulatory_status' => self::STATUS_PUBLISHED_SELF,
                'source_url' => self::COLPENSIONES_SOURCE,
            ],
        ];
    }

    /**
     * Institutions that were relevant and are not in the five above.
     *
     * Kept apart on purpose. Folding a historical administrator into the confirmed five would put a
     * name in the catalogue that no source here substantiates today.
     *
     * @return list<array{name: string, why_separate: string}>
     */
    public static function historicalCandidates(): array
    {
        return [
            [
                'name' => 'Positiva',
                'why_separate' => 'Figura en material de la Superintendencia Financiera sobre ACCAI. '
                    .'Ser administradora de ahorro individual no la convierte en administradora privada '
                    .'de fondos de pensiones, y no se registra como tal sin una fuente que lo diga.',
            ],
        ];
    }

    /**
     * Aliases **proposed**, never applied.
     *
     * @return list<array{token: string, target: string, confidence: string, reason: string}>
     */
    public static function proposedAliases(): array
    {
        return [
            [
                'token' => 'PORVENR',
                'target' => 'Porvenir',
                'confidence' => 'typo',
                'reason' => 'Transposición de letras en dos filas del archivo. Es un alias propuesto, no '
                    .'una administradora nueva, y no resuelve nada sin aprobación explícita.',
            ],
        ];
    }
}

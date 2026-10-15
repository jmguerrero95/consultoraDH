<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

/**
 * The catalogue this manifest is built on, and the honest limits of it.
 *
 * ## What "verified" means here, exactly
 *
 * A name is **verified** when an official source published it as an entity of that type. It is not
 * "a name somebody typed that looks like an organisation", and it is not "a name I am fairly sure
 * is right". The evidence is recorded per entry with its URL and the date that source carries,
 * because a list published in 2023 cannot testify to October 2026 and must not pretend to.
 *
 * ## What this manifest deliberately does not contain
 *
 * **No codes.** `social_security_entities.code` and `.tax_id` are nullable and stay null here. The
 * sources reached do publish codes, but only in documents this manifest has not read end to end;
 * a code transcribed from a snippet is a code nobody checked. A null code is visible and can be
 * filled from the registry. An invented one is invisible and gets trusted.
 *
 * **No AFP, no ARL, no CCF.** Those three sources were not readable: the Superintendencia Financiera
 * registry renders its results in JavaScript and returns no rows to an HTTP fetch, and the
 * Superintendencia del Subsidio Familiar site refused the connection. Rather than fill the gap from
 * memory — which is how a plausible wrong name enters a catalogue and then a payroll record — the
 * three types are absent, and `coverage()` says so.
 *
 * ## Why absence is recorded rather than left implicit
 *
 * `unverifiedByType()` exists so a caller can tell "this catalogue has nothing for AFP" apart from
 * "nobody looked". The first is a finding; the second is an oversight, and only one of them is safe
 * to act on.
 *
 * ## What is not here either
 *
 * No aliases. Those are proposals a person approves (§5.5's `import_source_mappings`), and folding
 * them into the catalogue would make an unapproved guess indistinguishable from a verified fact.
 * {@see proposedAliases()} lists them separately for exactly that reason.
 */
final class VerifiedEntityCatalogue
{
    /**
     * The one source this manifest rests on.
     *
     * Supersalud's EPS channel list is the regulator's own published roster of Entidades Promotoras
     * de Salud, and it carries its own publication date, which is what makes "as of" meaningful.
     */
    private const EPS_SOURCE = [
        'authority' => 'Superintendencia Nacional de Salud',
        'url' => 'https://www.supersalud.gov.co/es-co/Paginas/canales-de-atencion-eps.aspx',
        // The date the page itself carries, not the date this file was written.
        'published' => '2023-11-17',
    ];

    /**
     * EPS verified from {@see EPS_SOURCE}. Names are the ones the page publishes, spelled as it
     * spells them — `FUNDACIÓN SALUD MÍA` keeps its accent and its `FUNDACIÓN`, because a catalogue
     * that "cleans up" an official name stops matching the registry it claims to come from.
     *
     * @var list<array{type: SocialSecurityEntityType, name: string, code: null, tax_id: null, evidence: string}>
     */
    private const ENTITIES = [
        ['type' => SocialSecurityEntityType::Eps, 'name' => 'ASMET SALUD', 'code' => null, 'tax_id' => null],
        ['type' => SocialSecurityEntityType::Eps, 'name' => 'COMPENSAR', 'code' => null, 'tax_id' => null],
        ['type' => SocialSecurityEntityType::Eps, 'name' => 'COOSALUD', 'code' => null, 'tax_id' => null],
        ['type' => SocialSecurityEntityType::Eps, 'name' => 'FAMISANAR', 'code' => null, 'tax_id' => null],
        ['type' => SocialSecurityEntityType::Eps, 'name' => 'FUNDACIÓN SALUD MÍA', 'code' => null, 'tax_id' => null],
        ['type' => SocialSecurityEntityType::Eps, 'name' => 'MUTUAL SER', 'code' => null, 'tax_id' => null],
        ['type' => SocialSecurityEntityType::Eps, 'name' => 'NUEVA EPS', 'code' => null, 'tax_id' => null],
        ['type' => SocialSecurityEntityType::Eps, 'name' => 'SALUD TOTAL', 'code' => null, 'tax_id' => null],
        ['type' => SocialSecurityEntityType::Eps, 'name' => 'SANITAS', 'code' => null, 'tax_id' => null],
        ['type' => SocialSecurityEntityType::Eps, 'name' => 'SURA', 'code' => null, 'tax_id' => null],
    ];

    /**
     * Aliases **proposed**, never applied. Keyed by the token the workbook wrote.
     *
     * Each one is a judgement about a near-miss, and §9.2 forbids the parser making exactly this
     * judgement. They are listed so a person can approve or reject each one, not so the importer
     * can quietly adopt them.
     *
     * `confidence` is not a score: `exact` means the official name differs from the workbook token
     * by an accent or a particle only, and `typo` means the workbook's spelling is not a plausible
     * reading of the official name. Both still need a person.
     *
     * `type` never crosses. `COMPENSAR` is an EPS and a CCF and the two are separate catalogue rows,
     * so a token is only ever proposed against one type — never matched by name alone.
     *
     * @var array<string, list<array{type: SocialSecurityEntityType, token: string, target: string, confidence: string, reason: string}>>
     */
    private const ALIASES = [
        'eps' => [
            [
                'type' => SocialSecurityEntityType::Eps,
                'token' => 'SALUD MIA',
                'target' => 'FUNDACIÓN SALUD MÍA',
                'confidence' => 'exact',
                'reason' => 'El archivo omite «FUNDACIÓN» y la tilde. Mismo nombre oficial dentro de la lista de Supersalud.',
            ],
            [
                'type' => SocialSecurityEntityType::Eps,
                'token' => 'SURA EPS',
                'target' => 'SURA',
                'confidence' => 'exact',
                'reason' => 'El archivo añade el tipo al nombre. Supersalud publica «SURA» sin sufijo.',
            ],
            [
                'type' => SocialSecurityEntityType::Eps,
                'token' => 'SALUDTOTAL',
                'target' => 'SALUD TOTAL',
                'confidence' => 'typo',
                'reason' => 'Dos grafías de un mismo nombre en el mismo archivo: 258 filas con espacio y 8 sin él.',
            ],
            [
                'type' => SocialSecurityEntityType::Eps,
                'token' => 'MUTAEL SER',
                'target' => 'MUTUAL SER',
                'confidence' => 'typo',
                'reason' => 'Transposición de letras: MUTAEL por MUTUAL.',
            ],
        ],
        'afp' => [],
        'arl' => [],
        'ccf' => [],
    ];

    /**
     * @return list<array{type: SocialSecurityEntityType, name: string, code: null, tax_id: null, evidence: array<string, string>}>
     */
    public static function entities(): array
    {
        return array_map(
            static fn (array $entry): array => $entry + ['evidence' => self::EPS_SOURCE],
            self::ENTITIES,
        );
    }

    /**
     * @return list<array{type: SocialSecurityEntityType, token: string, target: string, confidence: string, reason: string}>
     */
    public static function proposedAliases(?SocialSecurityEntityType $type = null): array
    {
        $all = [];

        foreach (self::ALIASES as $group) {
            foreach ($group as $alias) {
                $all[] = $alias;
            }
        }

        if ($type === null) {
            return $all;
        }

        return array_values(array_filter($all, static fn (array $a): bool => $a['type'] === $type));
    }

    /**
     * How many verified names this catalogue has, by type.
     *
     * @return array<string, int>
     */
    public static function coverage(): array
    {
        $counts = array_fill_keys(array_map(
            static fn (SocialSecurityEntityType $t): string => $t->value,
            SocialSecurityEntityType::cases(),
        ), 0);

        foreach (self::ENTITIES as $entry) {
            $counts[$entry['type']->value]++;
        }

        return $counts;
    }

    /**
     * The types this manifest has nothing for, with the reason.
     *
     * Exposed rather than inferred from an empty list, because "checked and found unreadable" and
     * "nobody checked" must not read the same way to whoever activates this.
     *
     * @return array<string, string>
     */
    public static function unverifiedByType(): array
    {
        return [
            SocialSecurityEntityType::Afp->value => 'El registro de administradoras de la Superintendencia '
                .'Financiera construye sus resultados en JavaScript y no devuelve filas a una consulta HTTP. '
                .'No verificado.',
            SocialSecurityEntityType::Arl->value => 'No se alcanzó una fuente oficial legible en esta tanda. '
                .'No verificado.',
            SocialSecurityEntityType::Ccf->value => 'El sitio de la Superintendencia del Subsidio Familiar '
                .'rechazó la conexión. No verificado.',
        ];
    }

    /**
     * The source every verified entry rests on, and the date it carries.
     *
     * @return array<string, string>
     */
    public static function evidence(): array
    {
        return self::EPS_SOURCE;
    }
}

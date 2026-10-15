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
    public const SUBTYPES = [
        'afp_privada' => 'Administradora privada de fondos de pensiones.',
        'administradora_publica_rpm' => 'Administradora del régimen público de prima media. No es un fondo privado.',
        'accai' => 'Administradora de cuentas de ahorro individual. Es un rol adicional y no convierte a la entidad en AFP.',
    ];

    /**
     * Nothing is verified, so nothing is returned.
     *
     * @return list<array{registry: int, name: string, sigla: string, code: null, tax_id: null, flags?: list<string>}>
     */
    public static function records(): array
    {
        return [];
    }

    /**
     * Institutions named as candidates, with the reason each is still unverified.
     *
     * Not catalogue records. They are here so that whoever gets Superfinanciera's registry to answer
     * starts from a list and a reason rather than from nothing.
     *
     * @return list<array{name: string, expected_subtype: string, why_unverified: string}>
     */
    public static function unverifiedCandidates(): array
    {
        $registryBlocked = 'El registro SIMEV de la Superintendencia Financiera responde HTTP 403 a una '
            .'petición ordinaria y construye sus resultados en JavaScript. No se pudo leer.';

        return [
            ['name' => 'Protección', 'expected_subtype' => 'afp_privada', 'why_unverified' => $registryBlocked],
            ['name' => 'Porvenir', 'expected_subtype' => 'afp_privada', 'why_unverified' => $registryBlocked],
            ['name' => 'Colfondos', 'expected_subtype' => 'afp_privada', 'why_unverified' => $registryBlocked],
            ['name' => 'Skandia', 'expected_subtype' => 'afp_privada', 'why_unverified' => $registryBlocked],
            [
                'name' => 'Colpensiones',
                'expected_subtype' => 'administradora_publica_rpm',
                'why_unverified' => 'El sitio oficial responde HTTP 200 pero no publica NIT, razón social '
                    .'ni código regulatorio en las páginas alcanzables.',
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

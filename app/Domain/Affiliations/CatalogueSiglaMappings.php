<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Domain\Imports\SheetMonth;
use App\Domain\Imports\SourceEntityToken;

/**
 * The siglas a workbook writes and the catalogue row each one unambiguously names.
 *
 * ## Why this is a separate table and not a column
 *
 * `social_security_entities` has one name. A caja's name and its sigla are different strings, both
 * of them real, and a workbook writes the sigla where the catalogue holds the name. Adding a
 * `sigla` column would put one of many possible abbreviations into one fixed slot and would leave
 * `resolveEntity()` still indexing on name — the gap would move, not close.
 *
 * The importer already has the right home for it: `import_source_mappings`, which `resolveEntity()`
 * consults *before* the name index and only when a person has approved it. This class decides what
 * is worth proposing into it; {@see ApplyCatalogueSiglaMappings} writes the approved rows.
 *
 * ## The rule, and it is a narrow one
 *
 * A sigla is proposed **only when exactly one entity of that type answers to it**. Everything else
 * is refused, and the refusals are the interesting part:
 *
 * - `COMFENALCO` is six regional cajas. The workbook writes it bare. Resolving it would file every
 *   COMFENALCO affiliation under whichever caja the guess picked.
 * - `COMFAMILIAR` is three. Same argument.
 * - A sigla the source published corrupted is not proposed, because nobody can say what it was.
 * - A negation is never proposed. `NO` and `SIN CAJA` say there is no affiliation; giving them a
 *   mapping would manufacture a caja out of a refusal, and `SourceEntityToken::isNegativePhrase()`
 *   is asked first so the parser and this agree.
 *
 * Proposals are proposals. Nothing here writes a row, and {@see ApplyCatalogueSiglaMappings} requires
 * an explicit actor, so a sigla never becomes reusable by merely existing in this file.
 */
final class CatalogueSiglaMappings
{
    /**
     * Siglas the source itself flagged as shared, which can never be proposed.
     *
     * Taken from the catalogue's own `sigla_not_unique` flags rather than from a list written here,
     * so the two cannot drift apart.
     *
     * @var list<string>
     */
    private const REFUSED_AS_AMBIGUOUS = [];

    /**
     * @return list<array{type: SocialSecurityEntityType, token: string, canonical_name: string, reason: string}>
     */
    public static function proposals(): array
    {
        $proposals = [];

        foreach (self::catalogueRows() as $row) {
            if (! self::siglaIsUnambiguous($row['type'], $row['sigla'])) {
                continue;
            }

            // The parser's own refusal list, asked again here. Two modules disagreeing about
            // whether `NO` names something is how an entity called NO gets created.
            if (SourceEntityToken::isNegativePhrase(SheetMonth::fold($row['sigla']))) {
                continue;
            }

            // A sigla the source published corrupted cannot be proposed: nobody can say what the
            // document meant, and a corrected value is a guess wearing the source's name.
            if (in_array('source_artifact', $row['flags'] ?? [], true)) {
                continue;
            }

            // An entity the sectoral study records as having left the market is **not** proposed.
            // A reusable mapping outlives the question that justified it: approve `LIBERTY` today
            // and every future workbook saying LIBERTY resolves to a body that cannot write a
            // policy. That needs a person's decision with today's knowledge, not this manifest.
            if (in_array('left_the_market', $row['flags'] ?? [], true)) {
                continue;
            }

            // A sigla the workbook writes that is an employer paired with its administrator, not an
            // ARL name. Mapping `POSITIVA CC` to POSITIVA would be inventing what «CC» means.
            if (array_key_exists($row['sigla'], ArlNationalCatalogue::ambiguousTokens())) {
                continue;
            }

            $proposals[] = [
                'type' => $row['type'],
                'token' => $row['sigla'],
                'canonical_name' => $row['name'],
                'reason' => sprintf(
                    'La fuente publica «%s» como sigla de una sola entidad de tipo %s. El libro escribe '
                    .'la sigla, no el nombre institucional, así que sin este mapeo el token no resuelve.',
                    $row['sigla'],
                    $row['type']->value,
                ),
            ];
        }

        return $proposals;
    }

    /**
     * Whether this sigla can identify exactly one entity of that type.
     *
     * The check is a **prefix** test, not an equality test, and that distinction is the whole
     * reason it exists.
     *
     * `COMFENALCO` is the sigla of exactly one row — the Tolima caja — and the other five COMFENALCO
     * boxes carry qualified siglas (`COMFENALCO CARTAGENA`, `COMFENALCO SANTANDER`, …). Comparing
     * for equality therefore finds one match and calls the sigla unambiguous, which is precisely
     * backwards: a workbook writing `COMFENALCO` bare is writing the abbreviation the six boxes
     * share, and mapping it to the Tolima one would file every COMFENALCO affiliation under a caja
     * chosen by a guess.
     *
     * So a sigla is ambiguous when any other sigla of the same type **starts with it followed by a
     * space**. The trailing space is what keeps `CAFAM` and `CAFAMAZ` apart: they share a stem but
     * neither is an abbreviation of the other, and they are genuinely different cajas.
     */
    private static function siglaIsUnambiguous(SocialSecurityEntityType $type, string $sigla): bool
    {
        if (in_array(SheetMonth::fold($sigla), array_map(
            static fn (string $s): string => SheetMonth::fold($s),
            self::REFUSED_AS_AMBIGUOUS,
        ), true)) {
            return false;
        }

        $needle = SheetMonth::fold($sigla).' ';
        $exactMatches = 0;

        foreach (self::catalogueRows() as $row) {
            if ($row['type'] !== $type) {
                continue;
            }

            $candidate = SheetMonth::fold($row['sigla']);

            // Another box extends this sigla: it is the family abbreviation, not an identifier.
            if ($candidate !== SheetMonth::fold($sigla) && str_starts_with($candidate, $needle)) {
                return false;
            }

            if ($candidate === SheetMonth::fold($sigla)) {
                $exactMatches++;
            }
        }

        return $exactMatches === 1;
    }

    /**
     * Every catalogue row that has both a type and a sigla.
     *
     * EPS rows carry none: the Ministry's table publishes no abbreviations, so there is nothing to
     * propose for them and nothing to guess.
     *
     * @return list<array{type: SocialSecurityEntityType, sigla: string, name: string, flags?: list<string>}>
     */
    private static function catalogueRows(): array
    {
        $rows = [];

        foreach (CcfNationalCatalogue::records() as $record) {
            $rows[] = [
                'type' => SocialSecurityEntityType::Ccf,
                'sigla' => $record['sigla'],
                'name' => $record['name'],
                'flags' => $record['flags'] ?? [],
            ];
        }

        foreach (PensionNationalCatalogue::records() as $record) {
            $rows[] = [
                'type' => SocialSecurityEntityType::Afp,
                'sigla' => $record['sigla'],
                'name' => $record['name'],
                'flags' => $record['flags'] ?? [],
            ];
        }

        foreach (ArlNationalCatalogue::records() as $record) {
            $rows[] = [
                'type' => SocialSecurityEntityType::Arl,
                'sigla' => $record['sigla'],
                'name' => $record['name'],
                'flags' => $record['flags'] ?? [],
            ];
        }

        return $rows;
    }
}

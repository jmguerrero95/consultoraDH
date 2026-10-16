<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Domain\Imports\ImportPlanBuilder;
use App\Domain\Imports\SheetMonth;
use App\Domain\Imports\SourceEntityToken;
use App\Models\LegacyImport;
use App\Models\LegacyImportIssue;
use App\Models\LegacyImportRow;
use App\Models\SocialSecurityEntity;
use Illuminate\Support\Facades\DB;

/**
 * What the historical workbook actually resolved, per institutional type.
 *
 * ## Why this reads the importer and does not re-resolve anything
 *
 * The number that matters is not "how many institutions the catalogue holds". It is "how many tokens
 * *this file* stopped asking about". Those are different numbers and the first version of every
 * preview I have written confused them.
 *
 * So every figure here is derived from the artefacts the import itself produced — the staged rows,
 * the issues the reconstruction raised, and the catalogue rows that are actually usable — and never
 * from a second resolver. Where a token resolved, it resolved because `ImportPlanBuilder` would
 * resolve it: same `source_key`, same `type`, same `verified_at` requirement, same active-status
 * requirement. A token that this report calls resolved but the importer would refuse is a bug in
 * the report, and `RealWorkbookPreviewTest` checks the two agree.
 *
 * ## The buckets, and why there are six
 *
 *     resolved ...... a usable catalogue entity, by an approved mapping or by exact folded name
 *     negative ..... `NO`, `NINGUNA`, `NO APLICA`: the cell says there is none, and that is an answer
 *     refused ....... `UNO`, `TRES`, `AUXILIAR`, `MECANICO`: a word that names no administrator
 *     ambiguous .... a sigla several entities answer to, or one no source explains
 *     inactive ..... present in the catalogue, but not usable for a new affiliation
 *     pending ...... everything else, still asking
 *
 * `inactive` is separate from `pending` on purpose. Liberty is not an open question: it is a
 * documented entity that cannot take a **new** affiliation, and counting it as pending would tell a
 * reviewer to decide something that has already been decided.
 */
final class ImportEntityResolutionReport
{
    /** The staged column and the institutional type it fills. */
    private const COLUMNS = [
        'eps_token' => 'EPS',
        'ccf_token' => 'CCF',
        'afp_token' => 'AFP',
        'arl_token' => 'ARL',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function forImport(LegacyImport $import): array
    {
        $rows = LegacyImportRow::query()->where('legacy_import_id', $import->id)->get();
        $report = [];

        foreach (self::COLUMNS as $column => $type) {
            $report[$type] = self::forColumn($rows, $column, $type);
        }

        $report['blocking_issues'] = LegacyImportIssue::query()
            ->where('legacy_import_id', $import->id)
            ->where('blocking', true)
            ->whereNull('resolved_at')
            ->get(['code'])
            ->groupBy('code')
            ->map(fn ($group): int => $group->count())
            ->all();

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private static function forColumn($rows, string $column, string $type): array
    {
        /** @var array<string, int> $occurrences */
        $occurrences = [];

        foreach ($rows as $row) {
            $raw = (string) ($row->{$column} ?? '');

            if (trim($raw) === '') {
                continue;
            }

            $token = SheetMonth::fold($raw);
            $occurrences[$token] = ($occurrences[$token] ?? 0) + 1;
        }

        $buckets = [
            'resolved' => [], 'negative' => [], 'refused' => [],
            'ambiguous' => [], 'inactive' => [], 'pending' => [],
        ];
        $reasons = [];

        foreach (array_keys($occurrences) as $token) {
            $verdict = self::classify($token, $type);
            $buckets[$verdict['bucket']][] = $token;

            if ($verdict['reason'] !== null) {
                $reasons[$token] = $verdict['reason'];
            }
        }

        foreach ($buckets as $key => $tokens) {
            sort($tokens);
            $buckets[$key] = $tokens;
        }

        ksort($reasons);

        return [
            'unique_tokens' => count($occurrences),
            'occurrences' => array_sum($occurrences),
            'resolved' => count($buckets['resolved']),
            'negative' => count($buckets['negative']),
            'refused' => count($buckets['refused']),
            'ambiguous' => count($buckets['ambiguous']),
            'inactive' => count($buckets['inactive']),
            'pending' => count($buckets['pending']),
            'tokens' => $buckets,
            'pending_reasons' => $reasons,
        ];
    }

    /**
     * @return array{bucket: string, reason: string|null}
     */
    private static function classify(string $token, string $type): array
    {
        // A cell that denies an affiliation is an answer, not a question. `NO APLICA` and `NO` are
        // §9.1's refusals and were already being read as such by the parser.
        if (SourceEntityToken::isNegativePhrase($token)) {
            return ['bucket' => 'negative', 'reason' => 'La celda dice que no hay afiliación.'];
        }

        // `UNO`, `TRES`, `AUXILIAR`, `MECANICO`: a risk level or a job title read from the ARL
        // column. §9.1 refuses them as entity names and nothing may create one.
        if (SourceEntityToken::isNonEntityWord($token)) {
            return ['bucket' => 'refused', 'reason' => 'Nivel de riesgo o cargo, no una administradora.'];
        }

        $ambiguous = self::ambiguityReason($token, $type);

        if ($ambiguous !== null) {
            return ['bucket' => 'ambiguous', 'reason' => $ambiguous];
        }

        // Exactly what `ImportPlanBuilder::resolveEntity()` accepts: an approved mapping whose target
        // is active, or an active entity whose folded name is the token.
        $approved = self::approvedMappingFor($token, $type);

        if ($approved !== null) {
            return ['bucket' => 'resolved', 'reason' => null];
        }

        $byName = SocialSecurityEntity::query()
            ->where('type', $type)
            ->where('status', 'active')
            ->get()
            ->first(fn ($entity): bool => SheetMonth::fold((string) $entity->name) === $token);

        if ($byName !== null) {
            return ['bucket' => 'resolved', 'reason' => null];
        }

        // Present but deactivated: not an open question, a closed door.
        $inactive = SocialSecurityEntity::query()
            ->where('type', $type)
            ->where('status', '!=', 'active')
            ->get()
            ->first(fn ($entity): bool => SheetMonth::fold((string) $entity->name) === $token);

        if ($inactive !== null) {
            return ['bucket' => 'inactive', 'reason' => sprintf(
                '«%s» está en el catálogo pero inactiva: no admite afiliaciones nuevas.',
                $inactive->name,
            )];
        }

        return ['bucket' => 'pending', 'reason' => 'Ninguna entidad del catálogo corresponde a este token.'];
    }

    /**
     * The catalogue's own record of a sigla several entities answer to, or one no source explains.
     */
    private static function ambiguityReason(string $token, string $type): ?string
    {
        if ($type === 'CCF') {
            foreach (CcfNationalCatalogue::ambiguousSiglas() as $sigla) {
                if (SheetMonth::fold($sigla) === $token) {
                    return sprintf(
                        '«%s» es la sigla de varias cajas distintas. Hace falta la región.',
                        $sigla,
                    );
                }
            }

            foreach (CcfNationalCatalogue::records() as $record) {
                if (in_array('source_artifact', $record['flags'] ?? [], true)
                    && SheetMonth::fold($record['sigla']) === $token) {
                    return 'La fuente publica esta sigla corrupta y no se puede adivinar la real.';
                }
            }
        }

        if ($type === 'ARL') {
            foreach (ArlNationalCatalogue::ambiguousTokens() as $candidate => $why) {
                if (SheetMonth::fold($candidate) === $token) {
                    return $why;
                }
            }
        }

        if ($type === 'AFP') {
            foreach (PensionNationalCatalogue::proposedAliases() as $alias) {
                if (SheetMonth::fold($alias['token']) === $token) {
                    return sprintf(
                        'Corrección tipográfica propuesta hacia «%s», sin aprobar. No crea una entidad nueva.',
                        $alias['target'],
                    );
                }
            }
        }

        return null;
    }

    /**
     * The same query `resolveEntity()` makes: an approved mapping whose target is still active.
     */
    private static function approvedMappingFor(string $token, string $type): ?SocialSecurityEntity
    {
        return SocialSecurityEntity::query()
            ->whereIn('id', DB::table('import_source_mappings')
                ->where('profile', 'blinden_legacy_monthly_v1')
                ->where('type', $type)
                ->where('source_key', $token)
                ->whereNotNull('verified_at')
                ->pluck('social_security_entity_id'))
            ->where('status', 'active')
            ->first();
    }

    /**
     * A flat table, for a terminal or a report file.
     *
     * @return list<array<string, mixed>>
     */
    public static function toTable(LegacyImport $import): array
    {
        $report = self::forImport($import);
        $table = [];

        foreach (['EPS', 'CCF', 'AFP', 'ARL'] as $type) {
            foreach ($report[$type] as $metric => $value) {
                if (in_array($metric, ['tokens', 'pending_reasons'], true)) {
                    continue;
                }

                $table[] = ['tipo' => $type, 'métrica' => $metric, 'valor' => $value];
            }
        }

        foreach ($report['blocking_issues'] as $code => $count) {
            $table[] = ['tipo' => '—', 'métrica' => 'incidencia bloqueante: '.$code, 'valor' => $count];
        }

        return $table;
    }
}

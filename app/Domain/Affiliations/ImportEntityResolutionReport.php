<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Domain\Imports\ImportPlanBuilder;
use App\Domain\Imports\SheetMonth;
use App\Models\LegacyImport;
use App\Models\LegacyImportIssue;
use App\Models\LegacyImportRow;

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
     * Delegates to the shared resolver.
     *
     * There is no second copy of the rule here on purpose. While the preview judged for itself, the
     * two answers drifted, and the drift only showed in the direction that hides: the preview
     * reported tokens as resolved that the importer would have refused.
     *
     * @return array{bucket: string, reason: string|null}
     */
    private static function classify(string $token, string $type): array
    {
        $verdict = CatalogueEntityResolver::resolve($token, $type);

        return ['bucket' => $verdict['bucket'], 'reason' => $verdict['reason']];
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

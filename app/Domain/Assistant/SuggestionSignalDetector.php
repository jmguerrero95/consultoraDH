<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Imports\LegacyIssueSeverity;
use App\Domain\Receivables\ReceivablesService;
use App\Models\LegacyImport;
use App\Models\LegacyImportIssue;
use App\Models\User;

/**
 * Turns real operational state into candidate suggestions.
 *
 * ## What makes a signal a signal
 *
 * Every detector here answers the same question against the database: is there
 * something a person would want to know, that they may not already know, and
 * that the assistant can do something about? A detector that answers "yes"
 * during ordinary work is worse than no detector at all, because it teaches
 * people to ignore the ones that matter. The thresholds are therefore set where
 * "unusual" actually starts rather than where it is easy to notice.
 *
 * ## Deterministic first, model second
 *
 * Nothing in this class calls a model. Detection is arithmetic over rows, which
 * costs nothing, is reproducible, and cannot hallucinate an anomaly that the
 * data does not contain. The model is consulted later, and only for a candidate
 * that already has evidence behind it.
 *
 * ## Silence is a correct answer
 *
 * `detect()` returning an empty list is the normal case. That is not a failure
 * of the feature; it is the feature working, and it is what keeps a person from
 * being interrupted by an assistant that has nothing to add.
 */
final class SuggestionSignalDetector
{
    public function __construct(
        private readonly ReceivablesService $receivables,
    ) {}

    /**
     * Unresolved import issues of this severity or worse, on one import.
     *
     * Two is chosen because a single validation failure is a typo, and three is
     * a pattern. Reporting every one of them would be noise, and reporting none
     * would miss an import that is quietly wrong.
     */
    private const IMPORT_ISSUE_THRESHOLD = 2;

    /**
     * Share of a client's outstanding balance that is already past due.
     *
     * The ratio rather than an absolute amount: what makes overdue debt a
     * problem is how much of it there is, and a fixed peso threshold would fire
     * constantly for large clients and never for small ones.
     */
    private const OVERDUE_SHARE_THRESHOLD = 0.5;

    /** Minimum pesos overdue before the ratio is allowed to mean anything. */
    private const OVERDUE_FLOOR_COP = 1_000_000;

    /** Blocking issues on imports that are still waiting for review. */
    private const BLOCKED_IMPORT_THRESHOLD = 1;

    /**
     * Which screens each kind of problem belongs on.
     *
     * Detection used to run every rule regardless of where the person was, and
     * then hand the engine a collections finding while they were on the imports
     * screen. The engine could not filter that out afterwards: the suggestion
     * records the context it was *asked for*, so a mismatch was invisible by
     * the time it reached it.
     *
     * Each signal therefore declares where it is meaningful, and a signal with
     * no entry here is simply not offered on that screen. That is the mechanism
     * behind "do not drag one screen's problems into another" - it is enforced
     * where the knowledge lives, rather than inferred later from a string.
     */
    private const CONTEXT_IMPORTS = 'imports/issues';

    private const CONTEXT_CLIENT_ACCOUNT = 'receivables/client_account';

    private const CONTEXT_PORTFOLIO = 'receivables/portfolio';

    /**
     * Every candidate the current data supports for this person, best first.
     *
     * ## Why a list and not just the single best one
     *
     * Because the caller has to be able to *reject* candidates: a dismissal, an
     * acceptance or a cooldown removes one problem, and the engine then needs
     * somewhere else to go. Handing back only the top candidate made the whole
     * feature depend on that one row - a person who dismissed the highest-ranked
     * suggestion would silently stop receiving any suggestion at all, because the
     * same one kept being offered and kept being skipped. The rules that decide
     * whether someone may be interrupted belong in the engine, so the detector
     * hands over everything it found and lets them apply.
     *
     * @return list<array{
     *     category: string,
     *     module: string,
     *     operation: string,
     *     entity_type: ?string,
     *     entity_reference: ?string,
     *     action: string,
     *     evidence: array<string, mixed>,
     *     relevance: float,
     *     dedupe_key: string
     * }>
     */
    public function detect(User $user, string $module, string $operation): array
    {
        $wanted = $module.'/'.$operation;
        $candidates = [];

        // Several rules can share a context: the imports screen is served by both
        // the blocked-import and the repeated-failure rules, and dropping either
        // would silently reduce what that screen can say.
        foreach ([
            self::CONTEXT_IMPORTS => [
                fn (): array => $this->blockedImports($user),
                fn (): array => $this->repeatedImportInconsistencies($user),
            ],
            self::CONTEXT_CLIENT_ACCOUNT => [
                fn (): array => $this->overdueConcentration($user),
            ],
            self::CONTEXT_PORTFOLIO => [
                fn (): array => $this->overdueConcentration($user),
            ],
        ] as $context => $detectors) {
            if ($context !== $wanted) {
                continue;
            }

            foreach ($detectors as $detector) {
                foreach ($detector() as $candidate) {
                    $candidates[] = $candidate;
                }
            }
        }

        // Strongest first, so the engine takes the best thing that is still
        // admissible rather than the best thing overall and giving up.
        usort(
            $candidates,
            fn (array $a, array $b): int => $b['relevance'] <=> $a['relevance']
        );

        return $candidates;
    }

    /**
     * Imports that cannot proceed because they still have blocking issues.
     *
     * @return list<array<string, mixed>>
     */
    private function blockedImports(User $user): array
    {
        $blocking = LegacyImportIssue::query()
            ->where('blocking', true)
            ->whereNull('resolved_at')
            ->whereNull('superseded_at')
            ->whereHas('import', fn ($q) => $q->where('created_by', $user->id))
            ->selectRaw('legacy_import_id, count(*) as total')
            ->groupBy('legacy_import_id')
            ->havingRaw('count(*) >= ?', [self::BLOCKED_IMPORT_THRESHOLD])
            ->orderByDesc('total')
            ->limit(3)
            ->get();

        $candidates = [];

        foreach ($blocking as $row) {
            $import = LegacyImport::query()->find($row->legacy_import_id);

            if ($import === null) {
                continue;
            }

            $candidates[] = [
                'category' => 'import_blocked',
                'module' => 'imports',
                'operation' => 'issues',
                'entity_type' => 'legacy_import',
                'entity_reference' => (string) $import->id,
                'action' => 'Revisar y resolver los problemas bloqueantes de la importación antes de aplicarla.',
                'evidence' => [
                    'import_id' => $import->id,
                    'file_name' => $import->original_filename ?? null,
                    'blocking_issues' => (int) $row->total,
                    // `->value`, not the enum: this is stored as JSON and read
                    // back as text, and an enum object does not belong there.
                    'status' => $import->status?->value,
                ],
                // Scaled rather than constant: an import with two blockers is a
                // nudge, one with thirty is a fire.
                'relevance' => min(1.0, 0.6 + ((int) $row->total / 50)),
                'dedupe_key' => 'import_blocked:'.$import->id,
            ];
        }

        return $candidates;
    }

    /**
     * The same validation problem appearing again and again.
     *
     * Repetition is the signal, not the count: one import with three issues is a
     * mistake, but the same code failing across two imports is a mapping or a
     * source-data problem that will keep failing.
     *
     * @return list<array<string, mixed>>
     */
    private function repeatedImportInconsistencies(User $user): array
    {
        $repeated = LegacyImportIssue::query()
            ->whereNull('resolved_at')
            ->whereNull('superseded_at')
            ->whereIn('severity', [LegacyIssueSeverity::Error->value, LegacyIssueSeverity::Warning->value])
            ->whereHas('import', fn ($q) => $q->where('created_by', $user->id))
            ->selectRaw('code, count(*) as total, count(distinct legacy_import_id) as imports')
            ->groupBy('code')
            ->havingRaw('count(*) >= ?', [self::IMPORT_ISSUE_THRESHOLD])
            ->havingRaw('count(distinct legacy_import_id) >= 2')
            ->orderByDesc('total')
            ->limit(3)
            ->get();

        $candidates = [];

        foreach ($repeated as $row) {
            // `$row->code` is an enum, and an enum cannot be cast to a string in
            // PHP - `(string) $someEnum` throws. It also has no place in the JSON
            // evidence, where the stored value has to be the wire value so the
            // row can be read back and compared as text.
            $code = $row->code->value;

            $candidates[] = [
                'category' => 'import_inconsistency',
                'module' => 'imports',
                'operation' => 'issues',
                'entity_type' => 'import_issue_code',
                'entity_reference' => $code,
                'action' => 'Revisar la equivalencia que produce este error: se repite en varias importaciones y no se resolverá corrigiendo una fila.',
                'evidence' => [
                    'code' => $code,
                    'occurrences' => (int) $row->total,
                    'distinct_imports' => (int) $row->imports,
                ],
                'relevance' => min(1.0, 0.5 + ((int) $row->total / 40)),
                'dedupe_key' => 'import_inconsistency:'.$code,
            ];
        }

        return $candidates;
    }

    /**
     * Clients whose outstanding balance is mostly already past due.
     *
     * This is the one signal here that is predictive rather than merely
     * factual: a client with almost everything late is a different situation
     * from one with a little, and the difference is visible before anyone asks
     * for it.
     *
     * The figures come from `ReceivablesService` rather than from a second copy
     * of the balance arithmetic. A balance is obligation + adjustments - live
     * allocations, and "overdue" is relative to an as-of date; all of that is
     * already spelled out once, and a suggestion that disagrees with the
     * statement screen about who owes what is worse than silence.
     *
     * @return list<array<string, mixed>>
     */
    private function overdueConcentration(User $user): array
    {
        $rows = $this->receivables->clientsWithConcentratedOverdue(
            minimumShare: self::OVERDUE_SHARE_THRESHOLD,
            minimumBalanceCop: self::OVERDUE_FLOOR_COP,
        );

        $candidates = [];

        foreach ($rows as $row) {
            $candidates[] = [
                'category' => 'overdue_concentration',
                'module' => 'receivables',
                'operation' => 'client_account',
                'entity_type' => 'client',
                'entity_reference' => (string) $row['client_id'],
                'action' => 'Revisar la cuenta de este cliente: la mayor parte de su saldo ya está vencida.',
                'evidence' => [
                    'client_id' => $row['client_id'],
                    'client_name' => $row['name'],
                    'document_number' => $row['document_number'],
                    'outstanding_cop' => $row['balance_cop'],
                    'overdue_cop' => $row['overdue_balance_cop'],
                    'overdue_share' => $row['overdue_share'],
                    'overdue_obligations' => $row['overdue_obligations'],
                    'open_obligations' => $row['open_obligations'],
                    'threshold' => self::OVERDUE_SHARE_THRESHOLD,
                ],
                // Weighted by how lopsided it is: half the balance late is
                // notable, all of it is a different conversation.
                'relevance' => min(1.0, 0.4 + (float) $row['overdue_share']),
                'dedupe_key' => 'overdue_concentration:'.$row['client_id'],
            ];
        }

        return $candidates;
    }
}

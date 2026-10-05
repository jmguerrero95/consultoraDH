<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Periods\MonthlyPeriod;
use Illuminate\Support\Carbon;

/**
 * Rebuilds the history the workbook implies, without writing any of it.
 *
 * ## The whole of §8.4, §8.5, §9.5 and §10, in one pure function
 *
 * Input: the parsed rows and the operator's retirement policy. Output: episodes, affiliation
 * segments, rate segments, and the issues a person has to answer. No database, no clock, no
 * network — which is what makes it testable against synthetic fixtures and repeatable, and what
 * lets §19's verifier run it over the real file and print aggregates only.
 *
 * The reconstruction never decides. Every uncertain *cell* became a problem in
 * `SourcePersonRow`; every case where the file is ambiguous about an *interval* rather than
 * about a cell — a disappearance, an overlap — becomes a blocking issue in
 * `HistoryReconstruction`. §8.5 is explicit that a parallel is never marked automatically, and
 * this class has no way to express one.
 */
final class HistoryReconstructor
{
    public function __construct(
        private readonly ImportRetirementPolicy $retirementPolicy = ImportRetirementPolicy::ManualOnly,
    ) {}

    /**
     * @param  list<SourcePersonRow>  $rows  in any order; the reconstructor sorts them
     */
    public function reconstruct(array $rows): HistoryReconstruction
    {
        $ordered = $this->chronological($rows);

        $episodes = $this->buildEpisodes($ordered);

        return new HistoryReconstruction(
            $episodes,
            $this->buildAffiliationSegments($ordered),
            $this->buildRateSegments($ordered),
            $this->lastMonth($ordered),
            $this->retirementPolicy,
            $this->findDisappearances($episodes, $this->lastMonth($ordered)),
            $this->findOverlaps($episodes),
        );
    }

    /**
     * Rows in sheet order.
     *
     * Chronological by month, then by row number, so two rows in the same month produce the
     * same answer on every run. §6.1 requires chronological order and §12.2 requires that
     * applying twice look like applying once, and both start here.
     *
     * @param  list<SourcePersonRow>  $rows
     * @return list<SourcePersonRow>
     */
    private function chronological(array $rows): array
    {
        usort(
            $rows,
            static fn (SourcePersonRow $a, SourcePersonRow $b): int => [$a->sheetMonthKey, $a->sourceRowNumber]
                <=> [$b->sheetMonthKey, $b->sourceRowNumber],
        );

        return array_values($rows);
    }

    /** @param list<SourcePersonRow> $rows */
    private function lastMonth(array $rows): ?string
    {
        $last = null;

        foreach ($rows as $row) {
            if ($last === null || strcmp($row->sheetMonthKey, $last) > 0) {
                $last = $row->sheetMonthKey;
            }
        }

        return $last;
    }

    // ------------------------------------------------------------- §8.1 / §8.2

    /**
     * §8.1's grouping key, and §8.2's closure.
     *
     * The date is the part that is not obvious: the workbook states a month for most rows, so
     * without it every month would look like a re-hire and one employment would become ten
     * relationships. With it, "the same person at the same employer with the same start date,
     * again next month" is one episode observed twice, which is what §8.1 says the repeated
     * months mean.
     *
     * @param  list<SourcePersonRow>  $rows
     * @return list<RelationshipEpisode>
     */
    private function buildEpisodes(array $rows): array
    {
        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];

        foreach ($rows as $row) {
            // A row with no usable document cannot be attributed to a person, and §7.1 makes it
            // a blocker rather than a client identified by name. Grouping it would invent a
            // relationship for somebody the file never named.
            if (! $row->document->isUsable()) {
                continue;
            }

            $start = $row->affiliationDate;
            $key = implode('|', [$row->document->label(), $row->company->identityKey(), $start->isoDate ?? 'unknown']);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'client_key' => $row->document->label(),
                    'document_type' => $row->document->type?->value,
                    'document_number' => $row->document->number,
                    'company_tax_id' => $row->company->taxId,
                    'company_name' => $row->company->name,
                    'client_display_name' => $row->displayName(),
                    'precision' => $start->precision,
                    'date' => $start->isoDate,
                    'months' => [],
                    'source_rows' => [],
                    'retirement' => null,
                ];
            }

            if (! in_array($row->sheetMonthKey, $groups[$key]['months'], true)) {
                $groups[$key]['months'][] = $row->sheetMonthKey;
            }

            $groups[$key]['source_rows'][] = $row->sourceRowNumber;

            // The latest retirement note wins. Two months disagreeing about one departure is a
            // contradiction the row-level issues already flagged, and the more recent
            // observation is the one a person would act on.
            if ($row->retirement !== null) {
                $groups[$key]['retirement'] = $row->retirement;
            }
        }

        $episodes = [];

        foreach ($groups as $group) {
            $episodes[] = new RelationshipEpisode(
                $group['client_key'],
                $group['document_type'],
                $group['document_number'],
                $group['company_tax_id'],
                $group['company_name'],
                $this->intervalFor($group),
                $group['months'],
                $group['source_rows'],
                $group['retirement'],
                $group['client_display_name'],
            );
        }

        usort(
            $episodes,
            static fn (RelationshipEpisode $a, RelationshipEpisode $b): int => [
                $a->interval->start?->format('Y-m-d') ?? '0000-00-00',
                $a->clientKey,
                $a->companyTaxId ?? '',
            ] <=> [
                $b->interval->start?->format('Y-m-d') ?? '0000-00-00',
                $b->clientKey,
                $b->companyTaxId ?? '',
            ],
        );

        return $episodes;
    }

    /**
     * The interval for one group: a start from the date, and an end only from evidence.
     *
     * @param  array<string, mixed>  $group
     */
    private function intervalFor(array $group): HistoricalInterval
    {
        $start = HistoricalInterval::fromUnknownStart()->startingAt(
            $group['date'] === null ? null : Carbon::parse($group['date']),
            match ($group['precision']) {
                SourceAffiliationDate::PRECISION_MONTH => HistoricalInterval::MONTH,
                SourceAffiliationDate::PRECISION_UNKNOWN, null => HistoricalInterval::UNKNOWN,
                default => HistoricalInterval::DAY,
            },
        );

        $end = $group['retirement']?->endBoundary($this->retirementPolicy);

        // §8.2: only the policy may derive a date, and `manual_only` derives none. The boundary
        // it can derive uses the month and nothing else, so it carries `month`.
        return $end === null ? $start : $start->endingOn($end, HistoricalInterval::MONTH);
    }

    // --------------------------------------------------------------------- §8.4

    /**
     * Episodes that stop appearing before the last month of the file, with no closure evidence.
     *
     * ## Why absence is not a closure
     *
     * Closing one would delete a month of history that A03's obligations may still need, and it
     * would do so from no evidence at all. §8.4 makes this a blocker and notes the class is
     * small — about ten episodes in the real file — precisely so it can be a queue of ten
     * questions rather than a global rule.
     *
     * An episode seen in the last month of the file is *not* a disappearance: somebody still on
     * the payroll has no reason to be retired, and §8.4 says that case may be proposed open.
     *
     * @param  list<RelationshipEpisode>  $episodes
     * @return list<Disappearance>
     */
    private function findDisappearances(array $episodes, ?string $lastMonth): array
    {
        if ($lastMonth === null) {
            return [];
        }

        $found = [];

        foreach ($episodes as $episode) {
            $seen = $episode->lastMonth();

            if ($episode->hasClosureEvidence() || $seen === null || strcmp($seen, $lastMonth) >= 0) {
                continue;
            }

            $found[] = new Disappearance(
                $episode->key(),
                $episode->clientKey,
                $episode->companyTaxId,
                $episode->companyName,
                $episode->firstMonth(),
                $seen,
                $lastMonth,
                $episode->sourceRowIds,
            );
        }

        return $found;
    }

    // --------------------------------------------------------------------- §8.5

    /**
     * Clients observed at two companies at once.
     *
     * ## Why the test is shared months and not interval overlap
     *
     * §8.5 says being seen at several companies in the same month does *not* mean a parallel,
     * "en el archivo real aparece masivamente durante retiros/traslados", and it is emphatic
     * enough to repeat the warning. So the first cut of this method tested the rebuilt
     * intervals, and it reported **782** overlaps in the real file against 320 identities.
     *
     * That number is the bug, and the reason is structural: most episodes carry no closure
     * evidence, so most intervals are open, and two open intervals overlap by definition. The
     * test was therefore measuring "this person's first relationship was never closed", which
     * is true of almost every episode in any workbook and says nothing about parallelism.
     *
     * The evidence that actually distinguishes a parallel is co-observation: the person is in
     * the payroll of two employers in one month, which cannot be a sequential transfer. A
     * non-overlapping pair is a transfer or a disappearance, and both already have their own
     * question — a disappearance raises `relationship_disappeared_without_retirement`, and a
     * gap raises nothing because there is nothing to explain.
     *
     * The second condition keeps an overlap visible when both ends are dated and genuinely
     * intersect, which is the case a month-level test cannot see.
     *
     * @param  list<RelationshipEpisode>  $episodes
     * @return list<CompanyOverlap>
     */
    private function findOverlaps(array $episodes): array
    {
        /** @var array<string, list<RelationshipEpisode>> $byClient */
        $byClient = [];

        foreach ($episodes as $episode) {
            $byClient[$episode->clientKey][] = $episode;
        }

        $found = [];

        foreach ($byClient as $clientKey => $clientEpisodes) {
            if (count($clientEpisodes) < 2) {
                continue;
            }

            $count = count($clientEpisodes);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $first = $clientEpisodes[$i];
                    $second = $clientEpisodes[$j];

                    // Same company twice is two episodes of one employment, not a parallel.
                    if ($first->companyTaxId === $second->companyTaxId) {
                        continue;
                    }

                    $shared = $this->sharedMonths($first, $second);

                    if ($shared === [] && ! $this->bothClosedAndIntersecting($first, $second)) {
                        continue;
                    }

                    $found[] = new CompanyOverlap($clientKey, $first, $second, $shared);
                }
            }
        }

        return $found;
    }

    /** Whether both episodes have a dated end and those ends intersect. */
    private function bothClosedAndIntersecting(RelationshipEpisode $a, RelationshipEpisode $b): bool
    {
        if ($a->interval->isOpen() || $b->interval->isOpen()) {
            return false;
        }

        return $a->interval->overlaps($b->interval);
    }

    /**
     * The months both episodes were seen in — the evidence a reviewer reads first.
     *
     * @return list<string>
     */
    private function sharedMonths(RelationshipEpisode $a, RelationshipEpisode $b): array
    {
        return array_values(array_intersect($a->months, $b->months));
    }

    // --------------------------------------------------------------------- §9.5

    /**
     * Compress each affiliation column's snapshots into segments.
     *
     * The unit is `(client, company, type)`, so one person's EPS history at one employer is one
     * sequence of segments and two employers get two independent sequences. That is what makes
     * §9.5's "no two open affiliations of the same type" achievable: one stream yields one final
     * open segment.
     *
     * @param  list<SourcePersonRow>  $rows
     * @return list<AffiliationSegment>
     */
    private function buildAffiliationSegments(array $rows): array
    {
        /** @var array<string, list<array<string, mixed>>> $streams */
        $streams = [];

        foreach ($rows as $row) {
            if (! $row->document->isUsable()) {
                continue;
            }

            foreach ($row->entities as $typeValue => $token) {
                $type = SocialSecurityEntityType::tryFrom($typeValue);

                if ($type === null) {
                    continue;
                }

                $key = implode('|', [$row->document->label(), $row->company->identityKey(), $type->value]);

                $streams[$key][] = [
                    'month' => $row->sheetMonthKey,
                    'client' => $row->document->label(),
                    'document_type' => $row->document->type?->value,
                    'document_number' => $row->document->number,
                    'company_tax_id' => $row->company->taxId,
                    'type' => $type,
                    'token' => $token,
                    'risk' => $type === SocialSecurityEntityType::Arl
                        ? ArlRiskLevel::read($row->risk->riskClass, (string) ($row->risk->riskRaw ?? ''))
                        : null,
                    'row' => $row->sourceRowNumber,
                ];
            }
        }

        $segments = [];

        foreach ($streams as $stream) {
            $segments = array_merge($segments, $this->compressAffiliations($stream));
        }

        return $segments;
    }

    /**
     * One `(client, company, type)` sequence of months, folded into segments.
     *
     * A month whose snapshot equals the current segment's extends it. A month that differs
     * closes it and opens a new one on the first of that month with `month` precision, because
     * §9.5 says a change detected for the first time in a month is proposed at the start of
     * that month. The first segment of a stream starts `unknown`: nothing before the first
     * observation can be dated.
     *
     * @param  list<array<string, mixed>>  $stream
     * @return list<AffiliationSegment>
     */
    private function compressAffiliations(array $stream): array
    {
        $byMonth = [];

        foreach ($stream as $entry) {
            // A repeated snapshot for a month is not a change. Last one wins, which is the
            // same rule episodes use for a retirement note.
            $byMonth[$entry['month']] = $entry;
        }

        ksort($byMonth);

        $segments = [];

        /** @var list<array<string, mixed>> $run */
        $run = [];

        $closeRun = function (bool $isLast) use (&$run, &$segments): void {
            if ($run === []) {
                return;
            }

            $first = $run[0];
            $months = array_column($run, 'month');
            $startKey = $first['month'];

            // The very first observation of a stream cannot be dated — nothing before it was
            // seen — so it opens `unknown`. Every later change opens on the first of the month
            // it was seen in, which is §9.5's `month` precision.
            $startsUnknown = (bool) ($first['open_unknown'] ?? true);

            $interval = HistoricalInterval::fromUnknownStart()->startingAt(
                $startsUnknown ? null : MonthlyPeriod::fromKey($startKey)->startsOn(),
                $startsUnknown ? HistoricalInterval::UNKNOWN : HistoricalInterval::MONTH,
            );

            // Only a *closed* segment gets an end. The last run of a stream stays open, because
            // §9.5 forbids two open affiliations of one type and a person who is still
            // affiliated at the end of the file has not stopped. Closing it would mean the
            // import invented an end date, and then there would be no open affiliation at all.
            if (! $isLast) {
                $interval = $interval->endingOn(MonthlyPeriod::fromKey((string) end($months))->endsOnExclusive());
            }

            $segments[] = new AffiliationSegment(
                (string) $first['client'],
                // Both halves of the identity. A write resolves the client by
                // `document_type + document_number` (§7.1), so a payload carrying only the
                // number cannot be applied — and a segment that cannot be applied is a rate
                // that silently never appears.
                $first['document_type'] === null ? null : (string) $first['document_type'],
                $first['document_number'] === null ? null : (string) $first['document_number'],
                $first['company_tax_id'] === null ? null : (string) $first['company_tax_id'],
                $first['type'],
                $first['token'],
                $interval,
                $months,
                $first['risk'],
                array_map(static fn (array $entry): int => (int) $entry['row'], $run),
            );

            $run = [];
        };

        $previousToken = null;
        $isFirstSegment = true;

        foreach ($byMonth as $month => $entry) {
            /** @var SourceEntityToken $token */
            $token = $entry['token'];

            if ($previousToken !== null && $previousToken->sameAs($token)) {
                $run[] = $entry;

                continue;
            }

            // Every run but the one being replaced is now a past segment, so it closes.
            $closeRun(false);

            $entry['open_unknown'] = $isFirstSegment;
            $isFirstSegment = false;

            $run = [$entry];
            $previousToken = $token;
        }

        $closeRun(true);

        return $segments;
    }

    // ---------------------------------------------------------------------- §10

    /**
     * Compress each client's monthly value into rates.
     *
     * Equal consecutive values are one rate; a change is a new rate effective from the first of
     * the sheet's month. A month with no value produces nothing, and a value that is not
     * positive cannot reach here — `SourcePersonRow` already turned it into a blocker.
     *
     * @param  list<SourcePersonRow>  $rows
     * @return list<RateSegment>
     */
    private function buildRateSegments(array $rows): array
    {
        /** @var array<string, list<array<string, mixed>>> $streams */
        $streams = [];

        foreach ($rows as $row) {
            if (! $row->document->isUsable() || $row->amount === null) {
                continue;
            }

            $key = implode('|', [$row->document->label(), $row->company->identityKey()]);

            $streams[$key][] = [
                'month' => $row->sheetMonthKey,
                'client' => $row->document->label(),
                'document_type' => $row->document->type?->value,
                'document_number' => $row->document->number,
                'company_tax_id' => $row->company->taxId,
                // Whole pesos, as A03 stores them. The string is what the plan writes, so the
                // printed preview and the INSERT cannot disagree.
                'amount' => number_format($row->amount, 0, '.', ''),
                'row' => $row->sourceRowNumber,
            ];
        }

        $segments = [];

        foreach ($streams as $stream) {
            $byMonth = [];

            foreach ($stream as $entry) {
                $byMonth[$entry['month']] = $entry;
            }

            ksort($byMonth);

            /** @var list<array<string, mixed>> $run */
            $run = [];
            $previousAmount = null;

            $closeRun = function () use (&$run, &$previousAmount, &$segments): void {
                if ($run === []) {
                    return;
                }

                $segments[] = new RateSegment(
                    (string) $run[0]['client'],
                    $run[0]['document_type'] === null ? null : (string) $run[0]['document_type'],
                    $run[0]['document_number'] === null ? null : (string) $run[0]['document_number'],
                    $run[0]['company_tax_id'] === null ? null : (string) $run[0]['company_tax_id'],
                    MonthlyPeriod::fromKey((string) $run[0]['month']),
                    (string) $previousAmount,
                    array_column($run, 'month'),
                    array_map(static fn (array $entry): int => (int) $entry['row'], $run),
                );

                $run = [];
            };

            foreach ($byMonth as $month => $entry) {
                if ($previousAmount !== null && $previousAmount === $entry['amount']) {
                    $run[] = $entry;

                    continue;
                }

                $closeRun();
                $previousAmount = (string) $entry['amount'];
                $run = [$entry];
            }

            $closeRun();
        }

        return $segments;
    }
}

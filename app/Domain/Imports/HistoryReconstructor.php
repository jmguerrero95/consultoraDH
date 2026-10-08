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
    /**
     * @param  ImportDecisionSet|null  $decisions  the human answers in force for this import.
     *                                             NULL for §19's verifier, which runs the *untransformed* reconstruction on purpose:
     *                                             it reports what the file says, not what a reviewer decided.
     */
    public function __construct(
        private readonly ImportRetirementPolicy $retirementPolicy = ImportRetirementPolicy::ManualOnly,
        private readonly ?ImportDecisionSet $decisions = null,
    ) {}

    /**
     * @param  list<SourcePersonRow>  $rows  in any order; the reconstructor sorts them
     */
    public function reconstruct(array $rows): HistoryReconstruction
    {
        $ordered = $this->chronological($rows);

        $episodes = $this->buildEpisodes($ordered);

        $lastMonth = $this->lastMonth($ordered);

        $disappearances = $this->findDisappearances($episodes, $lastMonth);
        $overlaps = $this->findOverlaps($episodes);

        // §8.4 and §8.5's answers, applied *before* the questions are handed on.
        //
        // ## Why this has to happen here
        //
        // A04-R1 stored a disappearance decision and read it nowhere. `ImportDecisionSet::
        // disappearanceFor()` and `::overlapBoundaryFor()` existed, were documented, and had
        // **zero call sites** — so a reviewer who answered "close this relationship on
        // 2026-03-01" watched the issue turn resolved and the rebuilt plan propose the identical
        // open-ended episode, with no error anywhere. The answer was recorded and had no effect,
        // which is worse than not asking: it is a system that appears to work.
        //
        // The reconstruction is the only place that can honour them, because a boundary chosen by
        // a person has to be part of the interval *before* the interval becomes a plan action.
        // Reading it in the plan builder would mean the action's interval and the decision
        // disagreeing, and `plan_digest` would cover an interval that is not the one the reviewer
        // approved.
        $episodes = $this->applyDisappearanceDecisions($episodes, $disappearances);

        // ## Why the overlap list is rebuilt here, and not only below
        //
        // `§8.4`'s answer *creates* the overlap it did not exist before. A person with two
        // employers whose episodes are both open raises no §8.5 question — two open episodes are
        // refused on purpose, see `genuineOverlap()`. Answering that employer's disappearance bounds
        // it, and a bounded episode overlapping a still-open one **is** a genuine overlap. The
        // rebuild below already noticed: it raised the question, with the right subject, on the very
        // next plan.
        //
        // But `applyOverlapDecisions()` was reading the list computed from the *pre-decision*
        // episodes, so it saw nothing and applied nothing. The reviewer's `recognize_transfer` was
        // accepted, the issue was marked resolved, and both relationship actions kept
        // `overlap_resolution: none` — so A04 emitted two mutually exclusive ordinary opens and A02
        // refused the second, rolling the whole batch back.
        //
        // The question was asked and answered correctly and had no effect, which is the same defect
        // the comment above this method describes for A04-R1, one level along. Overlaps are now
        // recomputed from the decided episodes before §8.5's answers are applied, so the decision is
        // applied to the episodes the reviewer was actually shown.
        $overlaps = $this->findOverlaps($episodes);

        $episodes = $this->applyOverlapDecisions($episodes, $overlaps);

        // Recomputed: a decision can create a boundary, and §8.4's "does absence look like a
        // disappearance" has to be asked of the *decided* episodes, or an episode a person closed
        // is still reported as an open question on the next rebuild.
        $disappearances = $this->findDisappearances($episodes, $lastMonth);
        $overlaps = $this->findOverlaps($episodes);

        return new HistoryReconstruction(
            $episodes,
            $this->buildAffiliationSegments($ordered),
            $this->buildRateSegments($ordered),
            $lastMonth,
            $this->retirementPolicy,
            $disappearances,
            $overlaps,
        );
    }

    /**
     * §8.4's two answers: close the episode, or keep it open.
     *
     * ## `close_on_disappearance`
     *
     * Closes at the month after the last sighting — the boundary the disappearance itself
     * implies, and `month` precision, because a monthly snapshot cannot say the last day the
     * person worked. This is the same rule `ImportRetirementPolicy::month_end_boundary`
     * applies to a retirement note, and it is applied *per episode* because a person answered
     * about one episode, not about a policy.
     *
     * ## `keep_open`
     *
     * Changes the interval not at all, and that is the point: it is an explicit statement that
     * the gap is not a departure. The episode stops being a question because the person said so,
     * not because the code guessed. The alternative — treating silence as consent to close —
     * would delete a month of history from no evidence, which is the failure §8.4 exists to
     * prevent.
     *
     * @param  list<RelationshipEpisode>  $episodes
     * @param  list<Disappearance>  $disappearances
     * @return list<RelationshipEpisode>
     */
    private function applyDisappearanceDecisions(array $episodes, array $disappearances): array
    {
        if ($this->decisions === null || $disappearances === []) {
            return $episodes;
        }

        $byEpisode = [];

        foreach ($disappearances as $disappearance) {
            $byEpisode[$disappearance->episodeKey] = $disappearance;
        }

        foreach ($episodes as $index => $episode) {
            $disappearance = $byEpisode[$episode->key()] ?? null;

            if ($disappearance === null) {
                continue;
            }

            // The subject is the disappearance's own, built the same way the issue was built —
            // which is the whole reason §8.4's answer is findable at all.
            $resolution = $this->decisions->disappearanceFor(
                IssueSubject::disappearance($disappearance)->subject(),
            );

            if ($resolution === null) {
                continue;
            }

            $boundary = $resolution->boundary();

            if ($boundary === null) {
                // `keep_open`, or a close whose date could not be read. Either way the interval
                // is unchanged; the finding stops being reported because `findDisappearances()`
                // only reports what has no closure evidence.
                continue;
            }

            $episodes[$index] = $episode->withInterval(
                $episode->interval->endingOn($boundary->carbon(), $boundary->precision),
            );
        }

        return $episodes;
    }

    /**
     * §8.5's `split_overlap_at`: move the boundary of the *earlier* episode to the chosen month.
     *
     * ## Why the earlier one
     *
     * A transfer is written as a person leaving one employer before joining the next, and §8.5's
     * three answers are "corregir fecha", "reconocer transferencia" and "autorizar paralelo". A
     * boundary therefore ends one episode and opens the other at the same place, and the episode
     * that ends is the one that started first. Picking the later one would move the start of the
     * new employment backwards, which is the correction §8.5 says *not* to make.
     *
     * `overlap_resolution` is carried on the resulting action so §17.5 can show *why* the boundary
     * is where it is, rather than presenting a computed date as if the file had stated it.
     *
     * @param  list<RelationshipEpisode>  $episodes
     * @param  list<CompanyOverlap>  $overlaps
     * @return list<RelationshipEpisode>
     */
    private function applyOverlapDecisions(array $episodes, array $overlaps): array
    {
        if ($this->decisions === null || $overlaps === []) {
            return $episodes;
        }

        // Two separate things are collected, because §8.5's three answers do two separate jobs.
        //
        // A **boundary** only exists for `split_overlap_at`, which carries the date the reviewer
        // chose. `recognize_transfer` and `authorize_parallel` carry no date: the reviewer is
        // answering a question about the evidence, not correcting it.
        //
        // A **code** exists for all three, and it is what `ApplyImportPlan` needs in order to
        // choose A02's resolution. Without it the plan said `none`, the writer opened every
        // relationship with the ordinary resolution, and a reviewer who had explicitly authorised
        // a parallel got a row that did not claim to be one.
        $boundaries = [];
        $codes = [];

        foreach ($overlaps as $overlap) {
            $subject = IssueSubject::overlap($overlap)->subject();
            $decision = $this->decisions->overlapDecisionFor($subject);

            if ($decision === null) {
                continue;
            }

            $boundary = $this->decisions->overlapBoundaryFor($subject);

            if ($boundary !== null) {
                $boundaries[$overlap->first->key()] = $boundary;
            }

            $code = match ($decision) {
                // §8.5's "corregir fecha". Once the earlier episode ends where the reviewer said,
                // the two no longer overlap and the later one is a transfer — which is what A02
                // has to be told to do. There is deliberately no `split` code: `ApplyImportPlan`
                // has no such resolution, and emitting one made every split import fail at Apply
                // with "overlap_resolution: transfer, parallel, none o null" — a contradiction
                // the reviewer had just resolved.
                IssueResolutionDecision::SplitOverlapAt,
                IssueResolutionDecision::RecognizeTransfer => 'transfer',

                IssueResolutionDecision::AuthorizeParallel => 'parallel',

                default => null,
            };

            if ($code === 'transfer' && ! $this->transferIsExecutable($overlap)) {
                // §8.5's transfer needs a boundary it can name, and A02 closes the open relationship
                // at the *destination's* `started_on`.
                //
                // When both episodes start on the same day, that date identifies nothing: A02 would
                // close one employment on the day both began, which is the day the person was
                // already there. There is no evidence in the file about which came first, so there
                // is nothing to transfer from.
                //
                // Inventing one would be the worst outcome available: the reviewer's answer is
                // consumed, the plan looks executable, and the closed row is given a boundary that
                // says the employment ended before it started. So no code is written, neither episode
                // is marked, and the batch stays unappliable until the reviewer supplies a date
                // (`split_overlap_at`) or authorises the parallel (`authorize_parallel`) — both of
                // which name what A02 should do instead.
                //
                // This is insufficient temporal evidence in A04, not a defect in A02.
                continue;
            }

            if ($code !== null) {
                // On the **later** episode, in every case.
                //
                // The code selects how A02 opens *this* row, and both of the resolutions that use
                // it are about the row being opened: a transfer closes whatever is open before it,
                // and `ManageClientCompanies::link()` **refuses** `RESOLUTION_PARALLEL` outright
                // when nothing is open. Marking the earlier episode would therefore either degrade
                // silently or throw, and in the parallel case would fail the whole apply for a
                // decision the reviewer got exactly right.
                //
                // "Later" is chronological, and `findOverlaps()` walks the episodes in start order,
                // so `second` is the later one exactly when the starts differ — which is the only
                // case the guard above lets a transfer through. Company tax id is the tiebreak in
                // that sort and is never a substitute for chronology.
                //
                // §8.5's motive travels beside the machine code, never inside it:
                // `ApplyImportPlan::linkResolution()` switches on the code and refuses anything
                // else, and A02 stores the reason on its own column.
                $codes[$overlap->second->key()] = [
                    'code' => $code,
                    'reason' => $this->decisions->overlapParallelReason($subject),
                ];
            }
        }

        if ($boundaries === [] && $codes === []) {
            return $episodes;
        }

        foreach ($episodes as $index => $episode) {
            $boundary = $boundaries[$episode->key()] ?? null;
            $code = $codes[$episode->key()] ?? null;

            if ($boundary === null && $code === null) {
                continue;
            }

            $replacement = $episode;

            if ($boundary !== null) {
                $replacement = $replacement->withInterval(
                    $episode->interval->endingOn(Carbon::parse($boundary->date), $boundary->precision),
                );
            }

            if ($code !== null) {
                $replacement = $replacement->withOverlapResolution($code['code'], $code['reason']);
            }

            $episodes[$index] = $replacement;
        }

        return $episodes;
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
                    'company_verification_digit' => $row->company->verificationDigit,
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

            // §13's provenance. `legacy_import_rows.id`, never the Excel row number: row 42
            // exists in each of the ten sheet-months, so the number collides, matches nothing in
            // the database, and made `array_unique()` lossy. See `SourcePersonRow::provenanceIds()`.
            foreach ($row->provenanceIds() as $stagedId) {
                $groups[$key]['source_rows'][] = $stagedId;
            }

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
                $group['company_verification_digit'] ?? null,
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
     * ## §8.5 says the test is the rebuilt intervals
     *
     * The specification is two sentences, and they have to be read together:
     *
     * > "Mismo cliente observado en varias empresas el mismo mes NO significa automáticamente
     * > paralelismo: en el archivo real aparece masivamente durante retiros/traslados."
     *
     * > "**Después de reconstruir intervalos**: no hay solapamiento → normal; solapamiento real
     * > → `overlapping_company_history` blocker."
     *
     * So the warning is about co-observation *as a substitute* for the test, and the test is the
     * intervals. The previous implementation had it the other way round — it treated a shared
     * month as the trigger and consulted the intervals only as a narrow extra case — which
     * inverts §8.5 and produces the opposite failure: a genuine overlap between two episodes
     * that were never co-observed in one sheet (a re-hire whose start date nobody typed, next to
     * an earlier episode that ends inside it) is invisible.
     *
     * ## Why "real overlap" is not `overlaps()`
     *
     * `HistoricalInterval::overlaps()` returns `true` for any pair where either side is open,
     * because two unbounded spans cannot be proven disjoint. That is correct for the
     * intersection logic and useless as a finding: most episodes in any workbook carry no
     * closure evidence, so most intervals are open, and testing raw overlap raised **782**
     * `overlapping_company_history` blockers against 320 identities in the real file — a queue
     * nobody reads, which is the same failure §8.5's warning is about, reached by the other
     * route.
     *
     * So the overlap has to be *affirmative*, which means it needs a **bounded** piece of
     * evidence. Two cases qualify:
     *
     * 1. both episodes are closed and their intervals intersect — the boundaries are known and
     *    they overlap, so nothing about it is an artefact of a missing end;
     * 2. one is closed and the other's dated start falls strictly inside it — the open side
     *    began while the other was still running, which is an intersection the missing end
     *    cannot explain.
     *
     * ## Why "both open, and seen together" is *not* a third case
     *
     * A04-R1 first shipped three cases, and case 3 was "both episodes are open and were observed
     * in an overlapping month". Against the real workbook it reported **427** overlaps for 320
     * identities — and §19's verifier, once it was printing counts rather than booleans, said so.
     *
     * That is §8.5's own warning arriving through the other door. The specification says
     * co-observation "aparece masivamente durante retiros/traslados", and this shape is exactly
     * that: two unbounded episodes whose months happen to overlap. Two open intervals overlap by
     * definition, so asserting it says only that neither relationship was ever closed — which is
     * true of almost every episode in any workbook and tells a reviewer nothing about a parallel.
     *
     * The principle is §8.4's, applied symmetrically: **absence of evidence is not evidence of
     * ending, and it is not evidence of overlap either.** An overlap needs somebody's dated
     * boundary; co-observation is a question, and §8.4 already raises it as
     * `relationship_disappeared_without_retirement`. So case 3 is gone, and `sharedMonths` is
     * carried on {@see CompanyOverlap} as evidence for whoever answers that question.
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

                    if (! $this->genuineOverlap($first, $second)) {
                        continue;
                    }

                    $found[] = new CompanyOverlap(
                        $clientKey,
                        $first,
                        $second,
                        $this->sharedMonths($first, $second),
                    );
                }
            }
        }

        return $found;
    }

    /**
     * §8.5's "solapamiento real": an affirmative intersection of the rebuilt intervals.
     *
     * See this method's docblock for why that is not `$a->interval->overlaps($b->interval)` and
     * why it is not "they share a month". The three cases are, in order of how much they
     * depend on the evidence:
     *
     * 1. both closed and intersecting;
     * 2. one closed, the other's dated start strictly inside it.
     *
     * Two open episodes are never an overlap on their own; see this method's docblock for the
     * 427 blockers that cost before that was established.
     */
    private function genuineOverlap(RelationshipEpisode $a, RelationshipEpisode $b): bool
    {
        $first = $a->interval;
        $second = $b->interval;

        if ($first->start !== null && $second->start !== null && strcmp($first->start->toDateString(), $second->start->toDateString()) > 0) {
            [$first, $second] = [$second, $first];
        }

        // 1. Both closed. The boundaries are known and they intersect, so the overlap cannot be
        //    an artefact of a missing end date.
        if (! $first->isOpen() && ! $second->isOpen()) {
            return $first->overlaps($second);
        }

        // 2. One closed and the other dated: if the open one *began* while the closed one was
        //    still running, that is an intersection the missing end cannot explain.
        $closed = $first->isOpen() ? ($second->isOpen() ? null : $second) : $first;
        $open = $closed === $first ? $second : $first;

        if ($closed !== null && $open->start !== null && $closed->end !== null) {
            return $open->start->lt($closed->end);
        }

        // 3. Both open. Refused, deliberately — see this method's docblock. `overlaps()` would
        //    say yes by definition, and "both were seen in one month" is §8.5's warning about
        //    retirements and transfers rather than evidence of a parallel. The disappearance
        //    that a real sequential move leaves behind is raised by `findDisappearances()`, which
        //    is the question a reviewer actually needs to answer.
        return false;
    }

    /**
     * Can §8.5's transfer be carried out for this pair, from the evidence the file gives?
     *
     * `ManageClientCompanies::transferWithin()` closes the currently open relationship at the
     * **destination's** `started_on`. That date can only mean "this employment ended when the next
     * one began" if the two starts differ, so the pair is executable only then.
     *
     * Equal starts are the interesting case, not an edge: a payroll file that never carries a start
     * date gives every block the same one, so a person at two employers is *always* a same-start
     * pair. Refusing to guess here is what keeps the reviewer in the loop instead of letting A04
     * close an employment on the day it began.
     */
    private function transferIsExecutable(CompanyOverlap $overlap): bool
    {
        $source = $overlap->first->interval->start?->toDateString();
        $destination = $overlap->second->interval->start?->toDateString();

        return $source !== null && $destination !== null && $source !== $destination;
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

                // §9.5: "No permitir dos afiliaciones abiertas del mismo tipo."
                //
                // The constraint is about the **client and the type**, not the client, the company
                // and the type. A04-R1 keyed the stream by all three, so a person who moved from
                // employer A to employer B was two independent streams, each with its own open
                // segment — and §9.5's invariant was satisfied structurally while being violated in
                // fact: A03 would find two open EPS affiliations for one person and either read
                // them as two coverages or refuse to bill, and a transfer in the middle of a
                // coverage year is *normal*, not an error.
                //
                // Keying globally by `client + type` is what makes the transfer expressible: one
                // stream, one segment per entity, and the change of employer closes the first and
                // opens the second at the same boundary. The company stays on each segment as
                // context — it is where the affiliation hangs — but it is not part of the
                // stream's identity, because the thing §9.5 protects is one open EPS per person.
                $key = implode('|', [$row->document->label(), $type->value]);

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
                    // §13's provenance: real staged ids.
                    'source_rows' => $row->provenanceIds(),
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

        /**
         * Close the run that just ended.
         *
         * `$boundaryMonth` is the month the *next* run opens in, and §9.5 says the previous
         * segment closes "en la misma frontera" — the same boundary the next one opens on.
         *
         * ## The bug this fixes
         *
         * The previous version closed at `MonthlyPeriod::fromKey(end($months))->endsOnExclusive()`
         * — the exclusive end of the run's **last observed** month. That is the wrong boundary
         * whenever a month is missing from the file between two observations of the same stream:
         *
         * ```text
         *  ENERO   EPS A
         *  (no FEBRERO row for this client)
         *  MARZO   EPS B
         * ```
         *
         * A's run is `[ENERO]`, so its end was derived as end-of-January-exclusive = **1 Feb**,
         * and B opened on **1 Mar**. February belonged to no segment at all: not A, which ended
         * before it, and not B, which started after it. The audit's finding, exactly:
         * "`Jan=A, Mar=B` ⇒ A ends `2026-02-01`, leaving February uncovered. Correct is
         * `2026-03-01`."
         *
         * The gap is invisible in the review screen — two contiguous-looking segments, no
         * warning — and it is a hole in somebody's health history that A03 will read as "no EPS
         * that month". Deriving the boundary from the change rather than from the last sighting
         * is what §9.5 asks for: the change is first *seen* in March, and the monthly snapshot
         * can only say the change happened at March's start, not that A stopped in February.
         *
         * @param  string|null  $boundaryMonth  null for the last run, which stays open
         */
        $closeRun = function (?string $boundaryMonth) use (&$run, &$segments): void {
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
            if ($boundaryMonth !== null) {
                $interval = $interval->endingOn(
                    MonthlyPeriod::fromKey($boundaryMonth)->startsOn(),
                    HistoricalInterval::MONTH,
                );
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
                // §13: real staged row ids, deduplicated. The Excel row number stays in the
                // entries for the review screen's "sheet · fila" label, but it is not provenance.
                array_values(array_unique(array_merge(...array_map(
                    static fn (array $entry): array => $entry['source_rows'],
                    $run,
                ) ?: [[]]))),
            );

            $run = [];
        };

        $previousToken = null;
        $isFirstSegment = true;

        foreach ($byMonth as $month => $entry) {
            /** @var SourceEntityToken $token */
            $token = $entry['token'];

            // §17.4's `skip_affiliation`: a person confirmed that this cell must not become an
            // affiliation even though it named something. Treated as a refusal, so it *closes* a
            // segment rather than silently not producing one — which is the difference between
            // "this person left their EPS" and "this column is not something to read".
            $type = $entry['type'];

            if ($this->decisions?->skipsAffiliation($type, $token->token) === true) {
                $token = SourceEntityToken::restore(
                    $type,
                    $token->token,
                    SourceEntityToken::OUTCOME_NEGATIVE,
                    new SensitiveSourceRedactor,
                );
            }

            if ($previousToken !== null && $previousToken->sameAs($token)) {
                $run[] = $entry;

                continue;
            }

            // The run being replaced closes at the boundary the *next* run opens on. See
            // `$closeRun`'s docblock: deriving it from this run's last observed month left the
            // months between two observations covered by nothing at all.
            $closeRun((string) $month);

            $entry['open_unknown'] = $isFirstSegment;
            $isFirstSegment = false;

            $run = [$entry];
            $previousToken = $token;
        }

        // The last run has no successor, so it stays open.
        $closeRun(null);

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
                'source_rows' => $row->provenanceIds(),
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
                    array_values(array_unique(array_merge(...array_map(
                        static fn (array $entry): array => $entry['source_rows'],
                        $run,
                    ) ?: [[]]))),
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

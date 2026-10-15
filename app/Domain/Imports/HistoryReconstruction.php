<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * What the reconstruction found: the three kinds of segment, and the questions it raised.
 *
 * ## Why the issues live here and not in the parser
 *
 * A disappearance and an overlap are not properties of a *row* — they are properties of the
 * history several rows imply, and they can only be seen once the sheets are read in order and
 * grouped. Putting them in `ParsedWorkbook` would mean the parser holding the reconstruction's
 * state, and the parser's contract is "here is what each line says". This class is the second
 * half of that split, and it is where §8.4 and §8.5 are answered.
 *
 * ## Everything here is a proposal
 *
 * No segment writes anything. `HistoryReconstruction` is the input to the plan builder, which
 * consults the database, the operator's resolutions and the retirement policy before turning a
 * segment into an action. Two blocking lists — `disappearances()` and `overlaps()` — are what
 * §17.5's disabled Apply button and §15's 409 both read.
 */
final class HistoryReconstruction
{
    /**
     * @param  list<RelationshipEpisode>  $episodes
     * @param  list<AffiliationSegment>  $affiliationSegments
     * @param  list<RateSegment>  $rateSegments
     * @param  list<Disappearance>  $disappearances  §8.4 blockers
     * @param  list<CompanyOverlap>  $overlaps  §8.5 blockers
     * @param  list<ContradictoryStarts>  $contradictoryStarts  one employment whose stated starts contradict its months
     */
    public function __construct(
        private readonly array $episodes,
        private readonly array $affiliationSegments,
        private readonly array $rateSegments,
        private readonly ?string $fileLastMonth,
        private readonly ImportRetirementPolicy $retirementPolicy,
        private readonly array $disappearances = [],
        private readonly array $overlaps = [],
        private readonly array $contradictoryStarts = [],
        /**
         * FIX-IMPORT-HISTORY-04: relationships the monthly rule closed by itself.
         *
         * Carried rather than dropped, because §8 of the milestone requires the review screen to
         * show them as part of the summary. A closure the user did not make is still a decision
         * somebody has to be able to see — it just is not a question they have to answer.
         *
         * @var list<MonthlyObservationClosure>
         */
        private readonly array $monthlyClosures = [],
        private readonly ImportHistoryInterpretation $interpretation = ImportHistoryInterpretation::ExactEvents,
    ) {}

    /** @return list<MonthlyObservationClosure> */
    public function monthlyClosures(): array
    {
        return $this->monthlyClosures;
    }

    public function interpretation(): ImportHistoryInterpretation
    {
        return $this->interpretation;
    }

    /** @return list<RelationshipEpisode> */
    public function episodes(): array
    {
        return $this->episodes;
    }

    /** @return list<AffiliationSegment> */
    public function affiliationSegments(): array
    {
        return $this->affiliationSegments;
    }

    /** @return list<RateSegment> */
    public function rateSegments(): array
    {
        return $this->rateSegments;
    }

    /** @return list<Disappearance> */
    public function disappearances(): array
    {
        return $this->disappearances;
    }

    /** @return list<CompanyOverlap> */
    public function overlaps(): array
    {
        return $this->overlaps;
    }

    /** @return list<ContradictoryStarts> */
    public function contradictoryStarts(): array
    {
        return $this->contradictoryStarts;
    }

    public function fileLastMonth(): ?string
    {
        return $this->fileLastMonth;
    }

    public function retirementPolicy(): ImportRetirementPolicy
    {
        return $this->retirementPolicy;
    }

    /** §8.4 and §8.5 together: how many questions stand between this import and Apply. */
    public function blockingCount(): int
    {
        return count($this->disappearances) + count($this->overlaps);
    }

    /** Whether anything at all is blocking. §17.5 reads this to disable Apply. */
    public function isClear(): bool
    {
        return $this->blockingCount() === 0;
    }

    /**
     * The issues to persist, in the shape `ParsedIssue` uses.
     *
     * ## The subject is named here, because only here is it knowable
     *
     * A disappearance and an overlap are not about a *line*. They are about an interval implied
     * by many lines, so there is no `source_key` and no `company_block_key` to key them on — and
     * that is exactly why A04-R1 lost them. `StageLegacyImport::identityFor()` classified a
     * finding by looking for those keys, found neither, fell through to its last branch, and
     * hashed `subject=''` for all thirteen disappearances and all four overlaps. The unique index
     * then admitted the first of each and rejected the rest.
     *
     * So the interval findings carry an explicit {@see IssueSubject} built from the episode
     * identity §8.1 already gives us, and both readers use it verbatim.
     *
     * @return list<ParsedIssue>
     */
    public function issues(): array
    {
        $issues = [];

        foreach ($this->disappearances as $disappearance) {
            $issues[] = new ParsedIssue(
                LegacyImportIssue::RelationshipDisappearedWithoutRetirement,
                null,
                null,
                null,
                LegacyIssueSeverity::Error,
                true,
                $disappearance->message(),
                IssueSubject::disappearance($disappearance),
                [
                    'episode_key' => $disappearance->episodeKey,
                    'company_tax_id' => $disappearance->companyTaxId,
                    'first_month' => $disappearance->firstMonth,
                    'last_seen_month' => $disappearance->lastSeenMonth,
                    'file_last_month' => $disappearance->fileLastMonth,
                    'missing_months' => $disappearance->missingMonths(),
                ],
            );
        }

        foreach ($this->overlaps as $overlap) {
            $issues[] = new ParsedIssue(
                LegacyImportIssue::OverlappingCompanyHistory,
                null,
                null,
                null,
                LegacyIssueSeverity::Error,
                true,
                $overlap->message(),
                IssueSubject::overlap($overlap),
                $overlap->toArray(),
            );
        }

        // Blocking, and deliberately so.
        //
        // Every answer here is a date the file wrote, and until a person picks one the episodes
        // stay as they are — three contradictory relationships at one employer, which A02 refuses
        // and §12.3 turns into a refused batch. Refusing is the honest outcome: the file says two
        // incompatible things and no rule can say which one is true.
        foreach ($this->contradictoryStarts as $group) {
            $issues[] = new ParsedIssue(
                LegacyImportIssue::ContradictoryRelationshipStarts,
                null,
                null,
                null,
                LegacyIssueSeverity::Error,
                true,
                $group->explanation(),
                IssueSubject::contradictoryStarts($group),
                $group->toArray(),
            );
        }

        return $issues;
    }

    /**
     * Counts a review screen shows, and §19's verifier prints.
     *
     * Aggregates only — no name, no document, no credential — so this is safe to log.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $withEntity = 0;
        $refusals = 0;

        foreach ($this->affiliationSegments as $segment) {
            if ($segment->isAffiliation()) {
                $withEntity++;
            } elseif ($segment->isRefusal()) {
                $refusals++;
            }
        }

        return [
            'episodes' => count($this->episodes),
            'affiliation_segments' => count($this->affiliationSegments),
            'affiliation_segments_with_entity' => $withEntity,
            'affiliation_refusals' => $refusals,
            'rate_segments' => count($this->rateSegments),
            'disappearances' => count($this->disappearances),
            'overlaps' => count($this->overlaps),
            'contradictory_starts' => count($this->contradictoryStarts),
            // FIX-IMPORT-HISTORY-04. Reported next to `disappearances` on purpose: they are the
            // same observation — an episode that stopped appearing — settled two ways depending on
            // what the source means. A reviewer comparing the two numbers can see immediately
            // that this file needed no questions about departures.
            'monthly_closures' => count($this->monthlyClosures),
            'history_interpretation' => $this->interpretation->value,
            'blocking' => $this->blockingCount(),
        ];
    }

    /**
     * The monthly closures, for the review screen.
     *
     * @return list<array<string, mixed>>
     */
    public function monthlyClosureEvidence(): array
    {
        return [
            'criterion' => $this->interpretation->criterion(),
            'interpretation' => $this->interpretation->label(),
            'count' => count($this->monthlyClosures),
            'closures' => array_map(
                static fn (MonthlyObservationClosure $c): array => $c->toArray(),
                $this->monthlyClosures,
            ),
        ];
    }
}

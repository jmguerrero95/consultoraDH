<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Periods\GenerationBlocker;
use App\Models\ClientCompanyAssignment;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use Illuminate\Support\Collection;

/**
 * Works out which obligations a month *would* produce, without writing anything.
 *
 * ## The preview exists because generation is irreversible in practice
 *
 * Generating an obligation puts rows into the portfolio that payments will be made
 * against. An operator who triggers that by accident has to undo it with adjustments,
 * and the mistake is not obviously undoable from the screen that made it. So the same
 * question is answered twice: once for looking at, and once for acting on.
 *
 * ## Read-only, strictly
 *
 * This service writes nothing. No obligations, no audit business events, no changes to
 * rules, rates or relationships. `PreviewPeriodObligations` recomputes the candidates
 * inside the transaction rather than trusting this result: between an operator reading a
 * preview and confirming it, a rate may have been added or a relationship closed, and
 * the authoritative answer is the one computed under the lock.
 *
 * ## What a candidate is
 *
 * An A02 relationship **segment** that intersects the month, under the half-open
 * convention A02 already uses:
 *
 *     [started_on, ended_on)  ∩  [month, next month)  ≠ ∅
 *
 * which is
 *
 *     started_on < next_month  AND  (ended_on IS NULL OR ended_on > month)
 *     AND (ended_on IS NULL OR started_on < ended_on)
 *
 * The third clause is the one that was missing. The intersection formula assumes both
 * intervals are non-empty, and a same-day transfer produces a row with
 * `started_on = ended_on`, which is an **empty** interval: it covers no day at all. The
 * old query accepted it and billed for it, which is how a transfer on the fifteenth could
 * leave both the source and the destination company owing money for a day on which the
 * client worked for exactly one of them.
 *
 * ## One candidate per client and company, not per segment
 *
 * Segments are collapsed by `BillingCandidate`: non-overlapping ones become a single
 * candidate carrying all its provenance, and overlapping ones stay a blocker because
 * there is no honest answer to which company the debt belongs to. See that class for the
 * whole argument.
 *
 * ## An existing obligation is never a blocker
 *
 * This is the correction that matters most for month closing. A generated obligation
 * quotes the rate and the cutoff rule that were current when it was written, and those
 * decisions are immutable. If an operator later corrects a rate, or a relationship
 * closes, the configuration behind an obligation that already exists can legitimately
 * stop being resolvable *as current input* — and that must not stop the operator
 * generating the months' remaining missing obligations.
 *
 * So for a pair that already has an obligation, every configuration finding is reported
 * as a warning. Only a pair with **no** obligation can be blocked, and it is blocked by
 * what is missing for it.
 *
 * ## Bounded queries
 *
 * The configuration for the whole month is resolved in four queries regardless of how
 * many candidates there are — see `BatchedConfigResolver`. A hundred relationships
 * issue four queries, not four hundred.
 */
final class ObligationCandidateBuilder
{
    public function __construct(
        private readonly BatchedConfigResolver $config = new BatchedConfigResolver,
    ) {}

    /**
     * Every A02 relationship segment that intersects the month, non-empty ones only.
     *
     * One query, with the client and the company attached, because the preview has to
     * name them and loading them afterwards would be two queries per row.
     *
     * @return Collection<int, ClientCompanyAssignment>
     */
    public function segmentsFor(MonthlyPeriod $period): Collection
    {
        $month = $period->month();
        $endExclusive = $month->endsOnExclusive();

        return ClientCompanyAssignment::query()
            ->with(['client', 'company'])
            // Intersects the month: see the class docblock for the two inequalities.
            ->where('started_on', '<', $endExclusive)
            ->where(function ($query) use ($month): void {
                $query->whereNull('ended_on')
                    ->orWhere('ended_on', '>', $month->startsOn());
            })
            // Non-empty. `[started_on, ended_on)` with both equal is the empty
            // interval, and an empty interval intersects nothing. Without this a
            // same-day transfer or close produced a billing candidate for a day on
            // which the client worked for at most one of the two companies.
            ->where(function ($query): void {
                $query->whereNull('ended_on')
                    ->orWhereColumn('started_on', '<', 'ended_on');
            })
            // Deterministic for the preview, the collapse and the tests.
            ->orderBy('client_id')
            ->orderBy('company_id')
            ->orderBy('started_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * The month's candidates: one per client and company, with provenance.
     *
     * @return Collection<string, BillingCandidate> keyed by `client:company`
     */
    public function candidatesFor(MonthlyPeriod $period): Collection
    {
        return BillingCandidate::collapse($this->segmentsFor($period));
    }

    /**
     * Full preview of what generation would do.
     *
     * The counts are published as four separate, non-overloaded numbers plus a count of
     * findings, because the interface used to derive "existing" by subtracting
     * `blocker_count` from `candidate_count` — and `blocker_count` was a count of
     * *findings*, so a single candidate missing both a rate and a cutoff contributed two
     * to it and the subtraction went negative.
     *
     * @return array<string, mixed>
     */
    public function preview(MonthlyPeriod $period): array
    {
        $month = $period->month();

        $existing = MonthlyObligation::query()
            ->where('period_id', $period->id)
            ->get()
            ->keyBy(fn (MonthlyObligation $obligation): string => $obligation->client_id.':'.$obligation->company_id);

        $candidates = $this->candidatesFor($period);

        $pairs = $candidates
            ->map(fn (BillingCandidate $c): array => [$c->clientId, $c->companyId])
            ->values()
            ->all();

        $rates = $this->config->rates($pairs, $month);
        $cutoffs = $this->config->cutoffs($pairs, $month);

        $rows = [];
        $blockers = [];
        $warnings = [];

        $candidateCount = 0;
        $existingCount = 0;
        $creatableCount = 0;
        $blockedCount = 0;
        $resolvedCount = 0;
        $creatableAmount = 0;
        $resolvedPortfolioAmount = 0;

        foreach ($candidates as $candidate) {
            $candidateCount++;

            $key = $candidate->key();
            $alreadyExists = $existing->has($key);

            $rate = $rates[$key] ?? null;
            $rule = $this->config->ruleFor($candidate->clientId, $candidate->companyId, $cutoffs);

            $cutoff = $rule === null
                ? ResolvedCutoff::missing($candidate->clientId, $candidate->companyId, $month)
                : ResolvedCutoff::resolved(
                    $rule->resolveFor($month),
                    $rule,
                    $candidate->clientId,
                    $candidate->companyId,
                    $month,
                );

            // Whether this row would cause a write. Generation only ever creates what is
            // missing, so a row that already exists cannot block anything — see the
            // class docblock.
            $wouldWrite = ! $alreadyExists;

            $rowBlockers = [];
            $rowWarnings = [];

            $inactive = collect([
                ['label' => 'cliente', 'inactive' => $candidate->client !== null && ! $candidate->client->isActive()],
                ['label' => 'empresa', 'inactive' => $candidate->company !== null && ! $candidate->company->isActive()],
            ])->firstWhere('inactive', true);

            if ($inactive !== null) {
                $finding = GenerationBlocker::inactiveReference(
                    $candidate->clientId,
                    $candidate->companyId,
                    $month->key(),
                    sprintf('La %s está inactiva.', $inactive['label']),
                );

                $wouldWrite ? $rowBlockers[] = $finding : $rowWarnings[] = $finding->asWarning();
            }

            if ($candidate->hasOverlap()) {
                $finding = GenerationBlocker::overlappingRelationshipSegments(
                    $candidate->clientId,
                    $candidate->companyId,
                    $month->key(),
                    $candidate->overlappingAssignmentIds,
                );

                $wouldWrite ? $rowBlockers[] = $finding : $rowWarnings[] = $finding->asWarning();
            }

            // A month covered by a break in the employment record is ordinary. It is
            // reported, because "two relationships" with no explanation reads as a data
            // problem and it is not one.
            if ($candidate->sourceCount() > 1 && ! $candidate->hasOverlap()) {
                $rowWarnings[] = [
                    'code' => 'multiple_non_overlapping_segments',
                    'message' => sprintf(
                        'El cliente %d trabajó para la empresa %d en %d tramos dentro de %s; '
                        .'se factura una sola obligación que los reúne.',
                        $candidate->clientId,
                        $candidate->companyId,
                        $candidate->sourceCount(),
                        $month->key(),
                    ),
                    'context' => $candidate->provenance(),
                ];
            }

            if ($rate === null) {
                $finding = GenerationBlocker::missingRate(
                    $candidate->clientId,
                    $candidate->companyId,
                    $month->key(),
                    $candidate->client?->fullName(),
                    $candidate->company?->displayName(),
                );

                $wouldWrite ? $rowBlockers[] = $finding : $rowWarnings[] = $finding->asWarning();
            }

            if ($cutoff->isMissing()) {
                $finding = GenerationBlocker::missingCutoffRule(
                    $candidate->clientId,
                    $candidate->companyId,
                    $month->key(),
                    $candidate->client?->fullName(),
                    $candidate->company?->displayName(),
                );

                $wouldWrite ? $rowBlockers[] = $finding : $rowWarnings[] = $finding->asWarning();
            }

            if ($alreadyExists) {
                $rowWarnings[] = [
                    'code' => 'obligation_already_exists',
                    'message' => sprintf(
                        'Ya existe una obligación para el cliente %d y la empresa %d en %s; no se modificará.',
                        $candidate->clientId,
                        $candidate->companyId,
                        $month->key(),
                    ),
                    'context' => [
                        'client_id' => $candidate->clientId,
                        'company_id' => $candidate->companyId,
                        'obligation_id' => $existing->get($key)?->id,
                    ],
                ];
            }

            // Whether the configuration behind this row is complete right now. Tracked
            // on the row itself so `resolved_count` is a count of rows rather than a
            // second traversal re-deriving the same condition.
            $isResolved = $rate !== null && ! $cutoff->isMissing();

            if ($isResolved) {
                $resolvedPortfolioAmount += $rate->amount_cop;
                $resolvedCount++;
            }

            if ($alreadyExists) {
                $existingCount++;
            } elseif ($rowBlockers === []) {
                $creatableCount++;
                $creatableAmount += $rate?->amount_cop ?? 0;
            } else {
                $blockedCount++;
            }

            foreach ($rowBlockers as $blocker) {
                $blockers[] = $blocker->toArray();
            }

            foreach ($rowWarnings as $warning) {
                $warnings[] = $warning;
            }

            $rows[] = [
                'client_id' => $candidate->clientId,
                'company_id' => $candidate->companyId,
                'client_name' => $candidate->client?->fullName(),
                'company_name' => $candidate->company?->displayName(),
                // The single column the obligation carries. Null when several segments
                // produced it, because the provenance table holds the complete set and
                // picking one of them here would misattribute the debt.
                'assignment_id' => $candidate->primaryAssignmentId(),
                'source_assignment_ids' => $candidate->sourceAssignmentIds,
                'source_count' => $candidate->sourceCount(),
                'overlapping_assignment_ids' => $candidate->overlappingAssignmentIds,
                'already_exists' => $alreadyExists,
                'amount_cop' => $rate?->amount_cop,
                'rate_id' => $rate?->id,
                'cutoff' => $cutoff->toArray(),
                'blockers' => array_map(
                    fn (GenerationBlocker $blocker): array => $blocker->toArray(),
                    $rowBlockers,
                ),
                'warnings' => $rowWarnings,
                'resolved' => $isResolved,
                'will_be_created' => ! $alreadyExists && $rowBlockers === [],
            ];
        }

        return [
            'period' => $month->toArray(),
            // Four disjoint buckets that sum to `candidate_count`, so the interface can
            // never derive a negative number from them.
            'candidate_count' => $candidateCount,
            'existing_candidate_count' => $existingCount,
            'creatable_count' => $creatableCount,
            'blocked_candidate_count' => $blockedCount,
            // Findings, not rows. One blocked candidate with two findings counts twice
            // here and once in `blocked_candidate_count`, which is the honest reading of
            // both names.
            'blocker_finding_count' => count($blockers),
            // What this execution would write. Excludes existing obligations, whose
            // amounts are already in the portfolio and would otherwise be reported as
            // money about to be created.
            'creatable_amount_cop' => $creatableAmount,
            // The month as it currently stands once every candidate that can be resolved
            // is counted. Named for what it is: a portfolio total, not a pending write.
            'resolved_portfolio_amount_cop' => $resolvedPortfolioAmount,
            // How many candidates have their configuration resolved, whether or not
            // they will be written. Kept because it answers "how much of this month is
            // fully specified", which the other four counts do not.
            'resolved_count' => $resolvedCount,
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'candidates' => $rows,
            'can_generate' => $blockers === [],
        ];
    }
}

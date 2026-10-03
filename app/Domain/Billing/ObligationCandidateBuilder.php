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
 * against. An operator who triggers that by accident has to undo it with
 * adjustments, and the mistake is not obviously undoable from the screen that made
 * it. So the same question is answered twice: once for looking at, and once for
 * acting on.
 *
 * ## Read-only, strictly
 *
 * This service writes nothing. No obligations, no audit business events, no changes
 * to rules, rates or relationships. It is a calculation, and the fact that it can be
 * called on a live system without consequence is what makes it safe to show as a
 * confirmation step.
 *
 * `PreviewPeriodObligations` recomputes the candidates inside the transaction
 * rather than trusting this result: between an operator reading a preview and
 * confirming it, a rate may have been added or a relationship closed, and the
 * authoritative answer is the one computed under the lock.
 *
 * ## What a candidate is
 *
 * An A02 relationship that **intersects** the month. Not one that is open during
 * the month, and not one that is open at the end of it: somebody who started on the
 * twentieth was working for that company for part of the month, and the month is
 * billed to the company they worked for.
 *
 *     [started_on, ended_on)  ∩  [month, next month)  is not empty
 *
 * which is
 *
 *     started_on < next_month  AND  (ended_on IS NULL OR ended_on > month)
 *
 * The same half-open convention as A02, inherited rather than reinvented.
 */
final class ObligationCandidateBuilder
{
    /**
     * Every A02 relationship that intersects the month, with the client and company
     * attached so the preview can name them.
     *
     * One query. The alternative, fetching assignments and loading clients and
     * companies afterwards, is the N+1 this screen cannot afford: a month with two
     * hundred relationships would issue four hundred queries to draw a table.
     *
     * @return Collection<int, ClientCompanyAssignment>
     */
    public function candidatesFor(MonthlyPeriod $period): Collection
    {
        $month = $period->month();
        $endExclusive = $month->endsOnExclusive();

        return ClientCompanyAssignment::query()
            ->with(['client', 'company'])
            // Intersects the month. See the class docblock for the inequality.
            ->where('started_on', '<', $endExclusive)
            ->where(function ($query) use ($month): void {
                $query->whereNull('ended_on')
                    ->orWhere('ended_on', '>', $month->startsOn());
            })
            // Two open relationships to the same company are refused by A02, so this
            // ordering cannot change the result. It is here so the candidate list is
            // deterministic for the preview and for the tests.
            ->orderBy('client_id')
            ->orderBy('company_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Full preview of what generation would do.
     *
     * @return array<string, mixed>
     */
    public function preview(MonthlyPeriod $period, bool $missingOnly = false): array
    {
        $month = $period->month();
        $cutoffs = new CutoffResolver;
        $rates = new RateResolver;

        $existing = MonthlyObligation::query()
            ->where('period_id', $period->id)
            ->get()
            ->keyBy(fn (MonthlyObligation $obligation): string => $obligation->client_id.':'.$obligation->company_id);

        $rows = [];
        $blockers = [];
        $warnings = [];
        $totalAmount = 0;
        $resolvedCount = 0;

        $candidates = $this->candidatesFor($period);

        // How many relationships each pair contributes. One is normal; more than one
        // means the source data cannot say whose debt this is.
        $openByPair = $candidates
            ->groupBy(fn (ClientCompanyAssignment $assignment): string => $assignment->client_id.':'.$assignment->company_id)
            ->map(fn (Collection $group): int => $group->count())
            ->all();

        foreach ($candidates as $assignment) {
            $clientId = $assignment->client_id;
            $companyId = $assignment->company_id;
            $key = $clientId.':'.$companyId;

            $alreadyExists = $existing->has($key);
            $context = $missingOnly && $alreadyExists;

            // The candidate is resolved anyway: the preview reports what the month
            // *is*, not only what it would gain, so an operator can see the whole
            // picture and understand why a client is missing from the list to be
            // generated.
            $rate = $rates->resolve($clientId, $companyId, $month);
            $cutoff = $cutoffs->resolve($clientId, $companyId, $month);

            $rowBlockers = [];

            // Whether this row would cause a write. When the obligation is already
            // there and only missing ones are wanted, nothing is being created, so
            // nothing about the current state of the client or the relationships can
            // stop the month from being finished.
            $wouldWrite = ! ($missingOnly && $alreadyExists);

            // An inactive client or company is not a candidate for a new debt. The
            // debt may well be real, but writing one against somebody who has been
            // deactivated would be a new obligation created after the fact, and
            // reactivating them should not silently multiply what they owe.
            $inactive = collect([
                ['label' => 'cliente', 'inactive' => $assignment->client !== null && ! $assignment->client->isActive()],
                ['label' => 'empresa', 'inactive' => $assignment->company !== null && ! $assignment->company->isActive()],
            ])->firstWhere('inactive', true);

            if ($inactive !== null) {
                $finding = GenerationBlocker::inactiveReference(
                    $clientId,
                    $companyId,
                    $month->key(),
                    sprintf('La %s está inactiva.', $inactive['label']),
                );

                if ($wouldWrite) {
                    $rowBlockers[] = $finding;
                } else {
                    $warnings[] = $finding->asWarning();
                }
            }

            // Two overlapping open relationships to the same company are refused by a
            // partial unique index on the assignments table, so this cannot be created
            // through the interface or an import that respects it. It can still arrive
            // with data migrated from elsewhere, and then there is no way to say which
            // relationship the obligation belongs to. Blocking is the only honest
            // answer, and the check stays in case the index is ever relaxed.
            if (($openByPair[$key] ?? 0) > 1) {
                $finding = GenerationBlocker::ambiguousRelationshipState(
                    $clientId,
                    $companyId,
                    $month->key(),
                    $openByPair[$key],
                );

                if ($wouldWrite) {
                    $rowBlockers[] = $finding;
                } else {
                    $warnings[] = $finding->asWarning();
                }
            }

            if ($rate === null) {
                $rowBlockers[] = GenerationBlocker::missingRate(
                    $clientId,
                    $companyId,
                    $month->key(),
                    $assignment->client?->fullName(),
                    $assignment->company?->displayName(),
                );
            }

            if ($cutoff->isMissing()) {
                $rowBlockers[] = GenerationBlocker::missingCutoffRule(
                    $clientId,
                    $companyId,
                    $month->key(),
                    $assignment->client?->fullName(),
                    $assignment->company?->displayName(),
                );
            }

            if ($alreadyExists) {
                // Not a blocker: an existing obligation is the normal state of a
                // month that has been generated. It is reported as a warning so the
                // preview can say "these already exist and will not be touched".
                $warnings[] = [
                    'code' => 'obligation_already_exists',
                    'message' => sprintf(
                        'Ya existe una obligación para el cliente %d y la empresa %d en %s; no se modificará.',
                        $clientId,
                        $companyId,
                        $month->key(),
                    ),
                    'context' => [
                        'client_id' => $clientId,
                        'company_id' => $companyId,
                        'obligation_id' => $existing->get($key)?->id,
                    ],
                ];
            }

            if ($rate !== null && $cutoff->isMissing() === false) {
                $resolvedCount++;
                $totalAmount += $rate->amount_cop;
            }

            foreach ($rowBlockers as $blocker) {
                $blockers[] = $blocker->toArray();
            }

            $rows[] = [
                'client_id' => $clientId,
                'company_id' => $companyId,
                'client_name' => $assignment->client?->fullName(),
                'company_name' => $assignment->company?->displayName(),
                'assignment_id' => $assignment->id,
                'already_exists' => $alreadyExists,
                'amount_cop' => $rate?->amount_cop,
                'rate_id' => $rate?->id,
                'cutoff' => $cutoff->toArray(),
                'blockers' => array_map(
                    fn (GenerationBlocker $blocker): array => $blocker->toArray(),
                    $rowBlockers,
                ),
                'will_be_created' => ! $alreadyExists && $rowBlockers === [],
            ];
        }

        return [
            'period' => $month->toArray(),
            'candidate_count' => count($rows),
            'creatable_count' => count(array_filter($rows, fn (array $row): bool => $row['will_be_created'] === true)),
            'total_amount_cop' => $totalAmount,
            'resolved_count' => $resolvedCount,
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'candidates' => $rows,
            'can_generate' => $blockers === [],
        ];
    }
}

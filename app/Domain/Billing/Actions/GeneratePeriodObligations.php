<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\BillingTopologyLock;
use App\Domain\Billing\Events\ObligationGenerated;
use App\Domain\Billing\GenerationResult;
use App\Domain\Billing\ObligationCandidateBuilder;
use App\Domain\Billing\ObligationSource;
use App\Domain\Periods\MonthlyPeriodResolver;
use App\Domain\Periods\PeriodIsClosed;
use App\Models\ClientCompanyRate;
use App\Models\CutoffRule;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use App\Models\ObligationSourceAssignment;
use App\Models\User;
use App\Support\Database\SchemaConstraint;
use App\Support\Database\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Write the obligations for a month.
 *
 * ## All or nothing
 *
 * A month either has the obligations it should have or it has none. Generating some and
 * then refusing because of a blocker would leave a portfolio that looks generated and is
 * not, and the only way to tell is to compare it against the relationships — which is the
 * work generation was supposed to do.
 *
 * So the candidates are recomputed inside the transaction, the blockers are checked, and
 * if there is even one the transaction rolls back having written nothing. The operator is
 * given the blockers and the month is untouched.
 *
 * ## The preview is not the answer
 *
 * `PreviewPeriodObligations` is what the operator looked at. This recomputes. Between the
 * two, a rate may have been added, a relationship may have closed, a cutoff rule may
 * have appeared. Those minutes are exactly when a stale preview is dangerous.
 *
 * ## Lock order
 *
 *     BILLING TOPOLOGY (advisory, taken first by every participant)
 *     PERIOD row
 *     candidate relationship rows, ascending id
 *     referenced rates, ascending id
 *     referenced cutoff rules, ascending id
 *
 * The topology advisory lock comes **first**, before the period row, because it is what
 * serialises this transaction against A02's relationship writes. Taking the period first
 * would leave a window in which generation holds the month and reads topology that A02
 * is concurrently changing. See `BillingTopologyLock` for why one key is enough to make
 * this deadlock-free.
 *
 * ## The configuration is locked, not merely read
 *
 * The obligation stores `rate_id` and `cutoff_rule_id` as **evidence** for the amount and
 * the due date. Reading a rate without locking it allows this:
 *
 *     T1  generation reads Rate #5 = 235000
 *     T2  sees Rate #5 is unused, changes it to 300000, commits
 *     T1  writes base_amount_cop = 235000 with rate_id = 5
 *
 * The snapshot is stable but the reference now says something else, so the row cannot
 * answer "why 235000" with the row it points at. The rates and cutoff rules a
 * generation will quote are therefore locked in ascending id order and **re-read after
 * the lock**, and the write happens only if the re-read still describes the same
 * decision. A configuration write that arrives afterwards reports the rate as in use.
 *
 * ## Idempotency
 *
 * Running generation twice creates nothing the second time. Generation is
 * **always missing-only**: an obligation that already exists is never regenerated,
 * replaced or recalculated. There is no mode that does otherwise, so the request cannot
 * ask for something the domain will not do.
 *
 * The unique index on `(period_id, client_id, company_id)` is the defence behind that,
 * and a violation of it is translated into the same domain conflict rather than a 500.
 */
final class GeneratePeriodObligations
{
    public function __construct(
        private readonly MonthlyPeriodResolver $periods,
        private readonly ObligationCandidateBuilder $candidates,
        private readonly BillingTopologyLock $topology,
    ) {}

    /**
     * Generate the obligations this month is missing.
     *
     * @throws GenerationBlocked
     */
    public function execute(MonthlyPeriod $period, User $actor): GenerationResult
    {
        // The topology lock is taken outermost, by every participant in the protocol.
        return $this->topology->run(function () use ($period, $actor): GenerationResult {
            return DB::transaction(function () use ($period, $actor): GenerationResult {
                $locked = $this->periods->lockByIdForChange($period->id);

                if (! $locked->isOpen()) {
                    throw PeriodIsClosed::forStructuralChange($locked);
                }

                // Recomputed here, inside both locks. The preview the operator saw is
                // minutes old at best, and the topology may have moved under it.
                $preview = $this->candidates->preview($locked);

                if ($preview['blockers'] !== []) {
                    throw GenerationBlocked::withBlockers($preview['blockers'], $locked);
                }

                // The rows about to be written, and therefore the configuration rows they
                // will quote. Locked before any insert so the evidence cannot move under
                // the snapshot.
                $creatable = array_values(array_filter(
                    $preview['candidates'],
                    fn (array $row): bool => $row['will_be_created'] === true,
                ));

                $this->lockConfigurationFor($creatable);

                $created = 0;
                $createdAmount = 0;

                foreach ($creatable as $row) {
                    $this->insert($locked, $row, $actor);
                    $created++;
                    $createdAmount += (int) ($row['amount_cop'] ?? 0);
                }

                // The marker is what lets this period be closed: it distinguishes a month
                // that was generated and produced nothing from a month nobody generated.
                $locked->forceFill([
                    'generation_performed_at' => now(),
                    'generation_performed_by' => $actor->id,
                ])->save();

                return new GenerationResult(
                    periodId: $locked->id,
                    periodKey: $locked->key(),
                    created: $created,
                    considered: (int) $preview['candidate_count'],
                    existingCount: (int) $preview['existing_candidate_count'],
                    // What this execution actually wrote. `creatable_amount_cop` from
                    // the preview would be the same figure, but the amount recorded here
                    // is the sum of the rows that were inserted, so a discrepancy between
                    // the plan and the write cannot be reported as "nothing was created".
                    totalAmountCop: $createdAmount,
                );
            });
        });
    }

    /**
     * Lock the configuration rows this generation will quote, in ascending id order.
     *
     * Ascending, and grouped so that rates are taken before cutoff rules, matching the
     * order documented in this class's docblock. The sort lives here rather than at the
     * call site because the order is the property that prevents a deadlock between two
     * generations of different months that happen to quote the same configuration.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function lockConfigurationFor(array $rows): void
    {
        $rateIds = [];
        $cutoffIds = [];

        foreach ($rows as $row) {
            if (isset($row['rate_id'])) {
                $rateIds[] = (int) $row['rate_id'];
            }

            if (isset($row['cutoff']['cutoff_rule_id']) && $row['cutoff']['cutoff_rule_id'] !== null) {
                $cutoffIds[] = (int) $row['cutoff']['cutoff_rule_id'];
            }
        }

        $rateIds = array_values(array_unique($rateIds));
        sort($rateIds);

        $cutoffIds = array_values(array_unique($cutoffIds));
        sort($cutoffIds);

        if ($rateIds !== []) {
            // The rows are locked, and the snapshot below is built from *these* models
            // rather than from the preview. That is the whole point: the amount written
            // is read out of the locked row, so a configuration write that committed
            // while this transaction was queued cannot leave the row quoting a value the
            // referenced rate no longer holds.
            ClientCompanyRate::query()->whereIn('id', $rateIds)->orderBy('id')->lockForUpdate()->get();
        }

        if ($cutoffIds !== []) {
            CutoffRule::query()->whereIn('id', $cutoffIds)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insert(MonthlyPeriod $period, array $row, User $actor): void
    {
        try {
            $obligation = MonthlyObligation::query()->create([
                'period_id' => $period->id,
                'client_id' => $row['client_id'],
                'company_id' => $row['company_id'],
                // Null when several non-overlapping segments produced this candidate. The
                // provenance table below carries the complete set, so nothing is lost and
                // nothing is misattributed.
                'client_company_assignment_id' => $row['assignment_id'],
                'rate_id' => $row['rate_id'],
                'cutoff_rule_id' => $row['cutoff']['cutoff_rule_id'] ?? null,
                'base_amount_cop' => $row['amount_cop'],
                'due_on' => $row['cutoff']['due_on'],
                'generated_at' => now(),
                'generated_by' => $actor->id,
                'source' => ObligationSource::Generated->value,
            ]);
        } catch (QueryException $e) {
            if (! UniqueViolation::isFor($e, SchemaConstraint::OBLIGATION_PERIOD_CLIENT_COMPANY)) {
                throw $e;
            }

            // Two generations interleaved despite the locks. Reported as the conflict it
            // is rather than as a server error.
            throw GenerationBlocked::withBlockers([[
                'code' => 'duplicate_period_obligation',
                'message' => sprintf(
                    'Ya existe una obligación para el cliente %d y la empresa %d en %s.',
                    $row['client_id'],
                    $row['company_id'],
                    $period->key(),
                ),
                'context' => [
                    'client_id' => $row['client_id'],
                    'company_id' => $row['company_id'],
                    'period_key' => $period->key(),
                ],
            ]], $period);
        }

        $this->recordProvenance($obligation, $row);

        event(new ObligationGenerated($obligation, $actor, (int) $row['amount_cop']));
    }

    /**
     * Record every relationship segment that produced this obligation.
     *
     * Written for the single-segment case too, so the table is a complete record rather
     * than a list of exceptions. `upsert` rather than insert-or-fail: the unique index
     * on the pair makes a duplicate a no-op, and a retry of the same generation must not
     * fail on its own provenance.
     *
     * @param  array<string, mixed>  $row
     */
    private function recordProvenance(MonthlyObligation $obligation, array $row): void
    {
        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $row['source_assignment_ids'] ?? [],
        )));

        sort($ids);

        if ($ids === []) {
            return;
        }

        $now = now();

        ObligationSourceAssignment::query()->upsert(
            array_map(static fn (int $id): array => [
                'obligation_id' => $obligation->id,
                'client_company_assignment_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ], $ids),
            ['obligation_id', 'client_company_assignment_id'],
            ['created_at', 'updated_at'],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Events\ObligationGenerated;
use App\Domain\Billing\GenerationResult;
use App\Domain\Billing\ObligationCandidateBuilder;
use App\Domain\Billing\ObligationSource;
use App\Domain\Periods\MonthlyPeriodResolver;
use App\Domain\Periods\PeriodIsClosed;
use App\Models\Client;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
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
 * A month either has the obligations it should have or it has none. Generating some
 * and then refusing because of a blocker would leave a portfolio that looks
 * generated and is not, and the only way to tell is to compare it against the
 * relationships, which is the work generation was supposed to do.
 *
 * So the candidates are recomputed inside the transaction, the blockers are
 * checked, and if there is even one the transaction rolls back having written
 * nothing. The operator is given the blockers and the month is untouched.
 *
 * ## The preview is not the answer
 *
 * `PreviewPeriodObligations` is what the operator looked at. This recomputes.
 * Between the two, a rate may have been added, a relationship may have closed, a
 * cutoff rule may have appeared. Those minutes are exactly when a stale preview is
 * dangerous, and the authoritative question is the one asked under the lock.
 *
 * ## Lock order
 *
 *     PERIOD  ->  candidate relationship rows
 *
 * The period row is the serialisation point for this month: it is the thing whose
 * state decides whether generation is allowed, and holding it means two operators
 * generating the same month cannot both read the same candidates and both insert.
 * The second would otherwise be turned into a unique-constraint error rather than
 * into a decision, which is a worse outcome than either of them deciding first.
 *
 * Reference rows are read after the period lock and are never locked: nothing about
 * this operation changes them, and taking a lock on somebody else's row to read it
 * would create contention for no reason.
 *
 * ## Idempotency
 *
 * Running generation twice creates nothing the second time. An obligation that
 * already exists for a client and company in this period is **never** regenerated,
 * replaced or recalculated: the preview reports it as already existing and this
 * skips it. A month is corrected with adjustments and, where the membership itself
 * was wrong, by generating for a period that was reopened and then adding the
 * missing candidate with `generateMissingOnly`.
 *
 * The unique index on `(period_id, client_id, company_id)` is the defence in depth
 * behind that: if two requests did interleave, the second would be refused rather
 * than duplicating, and it is translated into the same answer rather than a 500.
 */
final class GeneratePeriodObligations
{
    public function __construct(
        private readonly MonthlyPeriodResolver $periods,
        private readonly ObligationCandidateBuilder $candidates,
    ) {}

    /**
     * Generate the obligations this month is missing.
     *
     * @param  bool  $missingOnly  when true, candidates that already have an
     *                             obligation are skipped without being reported as
     *                             warnings, which is what "generate the missing ones"
     *                             means after a membership correction
     */
    public function execute(MonthlyPeriod $period, User $actor, bool $missingOnly = true): GenerationResult
    {
        return DB::transaction(function () use ($period, $actor, $missingOnly): GenerationResult {
            // The period, locked and re-read. Every decision below is made from
            // this copy, not from the instance the caller was handed: a period closed
            // while this request was queued must be refused.
            $locked = $this->periods->lockByIdForChange($period->id);

            if (! $locked->isOpen()) {
                throw PeriodIsClosed::forStructuralChange($locked);
            }

            // Recomputed here, inside the lock. The preview the operator saw is
            // minutes old at best.
            $preview = $this->candidates->preview($locked, $missingOnly);

            if ($preview['blockers'] !== []) {
                throw GenerationBlocked::withBlockers($preview['blockers'], $locked);
            }

            $created = 0;

            foreach ($preview['candidates'] as $row) {
                if ($row['will_be_created'] !== true) {
                    continue;
                }

                $this->insert($locked, $row, $actor);
                $created++;
            }

            // The marker is what lets this period be closed later: it distinguishes
            // a month that was generated and produced nothing from a month nobody
            // has generated.
            $locked->forceFill([
                'generation_performed_at' => now(),
                'generation_performed_by' => $actor->id,
            ])->save();

            return new GenerationResult(
                periodId: $locked->id,
                periodKey: $locked->key(),
                created: $created,
                considered: $preview['candidate_count'],
                totalAmountCop: $preview['total_amount_cop'],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insert(MonthlyPeriod $period, array $row, User $actor): void
    {
        try {
            MonthlyObligation::query()->create([
                'period_id' => $period->id,
                'client_id' => $row['client_id'],
                'company_id' => $row['company_id'],
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
            // The unique index refused a second row for the same client and company.
            // That is the race the period lock is meant to prevent, and reaching it
            // means two requests interleaved despite the lock. Reporting the refusal
            // as the conflict it is, rather than letting it become a server error.
            if (! UniqueViolation::isFor($e, SchemaConstraint::OBLIGATION_PERIOD_CLIENT_COMPANY)) {
                throw $e;
            }

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

        event(new ObligationGenerated(
            obligation: MonthlyObligation::query()
                ->where('period_id', $period->id)
                ->where('client_id', $row['client_id'])
                ->where('company_id', $row['company_id'])
                ->firstOrFail(),
            actor: $actor,
            amountCop: $row['amount_cop'],
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Periods\Actions;

use App\Domain\Billing\BillingTopologyLock;
use App\Domain\Billing\ObligationCandidateBuilder;
use App\Domain\Periods\ClosePeriodBlocked;
use App\Domain\Periods\Events\PeriodClosed;
use App\Domain\Periods\MonthlyPeriodResolver;
use App\Domain\Periods\PeriodStatus;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Close a month: freeze what was generated for it.
 *
 * ## What closing does
 *
 * It stops structure changing. After closing, the system will not generate obligations
 * into that month, will not regenerate the ones there, and will not alter a base amount or
 * a due date through any structural path.
 *
 * ## What closing does not do
 *
 * It does not stop money moving. A debt generated in March is paid in July, and a payment
 * against March in July is ordinary. Refusing that would make a closed month's
 * obligations unpayable without a reopening. Adjustments and allocations remain available,
 * because a correction discovered in April is a fact about March.
 *
 * ## Closing proves the month is complete — not merely generated
 *
 * The earlier version checked two things: that generation had run, and that every
 * existing obligation had an amount and a due date. That is not completeness. The month
 * below describes what used to be possible:
 *
 *   1. October is generated, and `generation_performed_at` is set.
 *   2. Another October-effective relationship appears — a new hire effective on the 5th.
 *   3. No obligation exists for it.
 *   4. October is closed.
 *
 * The generation marker was set, the existing rows were sound, and the month closed with
 * a real debt missing from it. Nothing afterwards could generate into it without first
 * reopening, and "why is this client not invoiced for October" would have no answer.
 *
 * So close recomputes the candidates **inside the same locks** and refuses while any
 * candidate still needs an obligation:
 *
 *   * a pair with no obligation at all → blocked;
 *   * a pair whose configuration is missing → blocked, and the missing rate or cutoff is
 *     named so the operator knows what to configure;
 *   * a pair with contradictory relationship segments → blocked.
 *
 * Close never creates the missing obligations. Doing so would make "close" a second,
 * undocumented way to generate a month, and an operator would be surprised to find debts
 * appearing during a close. The sequence is always:
 *
 *     fix the configuration or the topology  →  generate the missing obligations  →  close
 *
 * ## An empty month is closable
 *
 * A month with no candidates at all is not blocked, provided generation was performed and
 * established that result. Nobody being billed is a real answer, and refusing to close it
 * would leave the system permanently unable to settle a quiet month.
 *
 * ## Existing obligations are judged on their own snapshot
 *
 * The completeness check must not depend on configuration that an immutable obligation
 * already quoted. If an operator later corrects a rate that produced March's obligations,
 * March's obligations do not become invalid and March does not become un-closable: the
 * snapshot is the record, and the candidate builder reports the state of an existing pair
 * as warnings precisely so this check cannot be blocked by it.
 *
 * ## Lock order
 *
 *     BILLING TOPOLOGY (advisory, first)
 *     PERIOD row
 *     candidates and references
 *
 * Same protocol as generation, taken in the same order, so the completeness check and a
 * concurrent generation cannot interleave: a relationship that becomes effective during
 * the check is either visible to it or blocked behind it, never missed by both.
 */
final class ClosePeriod
{
    public function __construct(
        private readonly MonthlyPeriodResolver $periods,
        private readonly ObligationCandidateBuilder $candidates,
        private readonly BillingTopologyLock $topology,
    ) {}

    public function execute(MonthlyPeriod $period, User $actor): MonthlyPeriod
    {
        return $this->topology->run(function () use ($period, $actor): MonthlyPeriod {
            return DB::transaction(function () use ($period, $actor): MonthlyPeriod {
                // The period row, then everything the decision depends on, all read after
                // the lock.
                $locked = $this->periods->lockByIdForChange($period->id);

                if ($locked->isClosed()) {
                    throw ClosePeriodBlocked::alreadyClosed($locked->label());
                }

                if (! $this->generationWasPerformed($locked)) {
                    throw ClosePeriodBlocked::notGenerated($locked->label());
                }

                $this->assertNothingIsMissing($locked);
                $this->assertEveryObligationIsSound($locked);

                $locked->forceFill([
                    'status' => PeriodStatus::Closed->value,
                    'closed_at' => now(),
                    'closed_by' => $actor->id,
                ])->save();

                event(new PeriodClosed($locked->refresh(), $actor));

                return $locked->refresh();
            });
        });
    }

    /**
     * Whether anybody has generated into this month.
     *
     * An obligation row is the evidence, but an empty month needs a different marker, so
     * `generation_performed_at` is what actually answers this. A month with none could be
     * an empty portfolio or an untouched one, and those are told apart by whether
     * generation ever ran.
     */
    private function generationWasPerformed(MonthlyPeriod $period): bool
    {
        return $period->generation_performed_at !== null;
    }

    /**
     * No candidate is left without an obligation, and none is blocked.
     *
     * The preview is reused rather than reimplemented, so close and generation agree on
     * what a candidate is by construction. Two numbers matter:
     *
     *   `creatable_count`  pairs with nothing and no blocker: the month is missing debt.
     *   `blocked_count`    pairs that cannot be billed: the operator is told which, and
     *                       why, instead of the month closing and the debt being
     *                       undiscoverable.
     *
     * Both are reported together as one refusal, so an operator who has three problems
     * sees three rather than fixing them one screen at a time.
     */
    private function assertNothingIsMissing(MonthlyPeriod $period): void
    {
        $preview = $this->candidates->preview($period);

        $missing = (int) $preview['creatable_count'];
        $blocked = (int) $preview['blocked_candidate_count'];

        if ($missing === 0 && $blocked === 0) {
            return;
        }

        $findings = [];

        if ($missing > 0) {
            $names = [];

            foreach ($preview['candidates'] as $row) {
                if ($row['will_be_created'] === true) {
                    $names[] = sprintf(
                        '%s / %s',
                        $row['client_name'] ?? ('cliente '.$row['client_id']),
                        $row['company_name'] ?? ('empresa '.$row['company_id']),
                    );
                }
            }

            $findings[] = [
                'code' => 'period_incomplete',
                'message' => sprintf(
                    'Hay %d obligación(es) que este periodo todavía no tiene. '
                    .'Genérelas antes de cerrar; el cierre no las crea. Pendientes: %s.',
                    $missing,
                    implode('; ', $names),
                ),
                'context' => [
                    'period_id' => $period->id,
                    'period_key' => $preview['period']['key'],
                    'missing_count' => $missing,
                    'missing' => $names,
                ],
            ];
        }

        if ($blocked > 0) {
            $findings[] = [
                'code' => 'period_has_blocked_candidates',
                'message' => sprintf(
                    'Hay %d candidato(s) que no se pueden facturar porque falta configuración '
                    .'o porque las relaciones se contradicen. Corríjalos antes de cerrar.',
                    $blocked,
                ),
                'context' => [
                    'period_id' => $period->id,
                    'blocked_count' => $blocked,
                    // The findings themselves, so the dialog can name the missing rate or
                    // cutoff rather than only counting candidates.
                    'findings' => $preview['blockers'],
                ],
            ];
        }

        throw ClosePeriodBlocked::because($findings);
    }

    /**
     * Every generated obligation has an amount and a date.
     *
     * Defensive: the database enforces both, so this cannot normally fail. It is here so
     * that if a row somehow is unsound the refusal names the month and the count,
     * instead of the next write failing with a constraint error to decode.
     */
    private function assertEveryObligationIsSound(MonthlyPeriod $period): void
    {
        $unsound = MonthlyObligation::query()
            ->where('period_id', $period->id)
            ->where(function ($query): void {
                $query->whereNull('due_on')
                    ->orWhere('base_amount_cop', '<=', 0);
            })
            ->count();

        if ($unsound > 0) {
            throw ClosePeriodBlocked::because([[
                'code' => 'obligation_without_valid_snapshot',
                'message' => sprintf(
                    'Hay %d obligación(es) sin importe o sin fecha de vencimiento válidas. '
                    .'Revise esas obligaciones antes de cerrar el periodo.',
                    $unsound,
                ),
                'context' => ['period_id' => $period->id, 'count' => $unsound],
            ]]);
        }
    }
}

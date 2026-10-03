<?php

declare(strict_types=1);

namespace App\Domain\Periods\Actions;

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
 * It stops structure changing. After closing, the system will not generate
 * obligations into that month, will not regenerate the ones there, and will not
 * alter a base amount or a due date through any structural path.
 *
 * ## What closing does not do
 *
 * It does not stop money moving. A debt generated in March is paid in July, and a
 * payment against March in July is ordinary. Refusing that would make a closed
 * month's obligations unpayable without a reopening, which is not what "closed"
 * means to anybody who has to pay it. Adjustments and allocations remain available
 * too, because a correction discovered in April is a fact about March, not an
 * attempt to change what March was.
 *
 * ## Before it closes
 *
 * The period row is locked and re-read, and the whole period is checked inside that
 * lock. Checking outside it, or before the transaction, is how a period ends up
 * closed after a generation that started while it was closing.
 *
 * Two things are verified, and one of them is deliberately not a blocker:
 *
 *   * that generation has been performed. A month that was never generated is
 *     indistinguishable from one somebody forgot, so it cannot be closed.
 *   * that every generated obligation has an amount and a due date. The database
 *     already refuses those rows; the check exists so the refusal happens here with
 *     a message rather than at the database with an error.
 *
 * A month with **no** obligations is not blocked, provided generation was performed
 * and produced nothing: an empty portfolio month is a real answer, and refusing to
 * close it would leave the system permanently unable to settle a month in which
 * nobody was billed.
 */
final class ClosePeriod
{
    public function __construct(
        private readonly MonthlyPeriodResolver $periods,
    ) {}

    public function execute(MonthlyPeriod $period, User $actor): MonthlyPeriod
    {
        return DB::transaction(function () use ($period, $actor): MonthlyPeriod {
            // The period row, then everything the decision depends on, all read
            // after the lock.
            $locked = $this->periods->lockByIdForChange($period->id);

            if ($locked->isClosed()) {
                throw ClosePeriodBlocked::alreadyClosed($locked->label());
            }

            $generationPerformed = $this->generationWasPerformed($locked);

            if (! $generationPerformed) {
                throw ClosePeriodBlocked::notGenerated($locked->label());
            }

            $this->assertEveryObligationIsSound($locked);

            $locked->forceFill([
                'status' => PeriodStatus::Closed->value,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ])->save();

            event(new PeriodClosed($locked->refresh(), $actor));

            return $locked->refresh();
        });
    }

    /**
     * Whether anybody has generated into this month.
     *
     * An obligation row is the evidence. A month with none could be an empty
     * portfolio or an untouched one, and those are told apart by whether generation
     * ever ran.
     *
     * `GENERATED` marks the intent explicitly rather than leaving an absence, so an
     * empty month can be distinguished from an ungenerated one. It lives in the same
     * row rather than in a separate table because it is a statement about the
     * operation, not a fact about a client.
     */
    private function generationWasPerformed(MonthlyPeriod $period): bool
    {
        return $period->generation_performed_at !== null;
    }

    /**
     * Every generated obligation has an amount and a date.
     *
     * Defensive: the database enforces both, so this cannot normally fail. It is
     * here so that if a row somehow is unsound the refusal names the month and the
     * count, instead of the next write failing with a constraint error the operator
     * would have to decode.
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

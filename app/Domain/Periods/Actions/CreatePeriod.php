<?php

declare(strict_types=1);

namespace App\Domain\Periods\Actions;

use App\Domain\Periods\Events\PeriodCreated;
use App\Domain\Periods\MonthlyPeriod as Month;
use App\Domain\Periods\PeriodAlreadyExists;
use App\Models\MonthlyPeriod as MonthlyPeriodRecord;
use App\Models\User;
use App\Support\Database\SchemaConstraint;
use App\Support\Database\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Open a month.
 *
 * Its own action rather than a method on the resolver. The resolver answers "which month
 * is current"; opening a month writes a row, emits an event and needs a transaction, and
 * those are different jobs from reading a value. When the two live together, the read
 * path grows transaction and event handling it never needs.
 *
 * ## Why `SELECT ... FOR UPDATE` is not enough here
 *
 * The obvious implementation locks the month before checking it:
 *
 *     SELECT ... FOR UPDATE WHERE period_month = '2026-10-01'
 *     if missing: INSERT
 *
 * and the lock does nothing in the case that matters. A row lock locks **rows that
 * exist**; the row being looked for does not exist yet, so nothing is locked, and two
 * concurrent requests both see "no October" and both insert. This is not a theoretical
 * interleaving — it is exactly what two operators pressing "Abrir periodo" on the same
 * month produce, and the second one receives a raw unique-constraint error.
 *
 * So the database constraint stays authoritative and its violation is translated. The
 * unique index on `period_month` is the real serialisation point for creation, exactly as
 * the period row lock is for everything that happens to an existing month, and the loser
 * of the race receives the same `PeriodAlreadyExists` a deliberate second attempt would
 * get — a named domain conflict the controller renders as 409, not a 500.
 *
 * There is no advisory lock here, deliberately. Creation is a single-row insert guarded
 * by a unique index; `BillingTopologyLock` exists to serialise *read-then-write* decisions
 * over many rows, and taking a global protocol lock to insert one row would serialise every
 * month opening in the system behind it for no benefit.
 */
final readonly class CreatePeriod
{
    /**
     * @throws PeriodAlreadyExists when the month already exists, whether it was created
     *                             deliberately or by a request that won the race
     */
    public function execute(Month $month, User $actor): MonthlyPeriodRecord
    {
        try {
            return DB::transaction(function () use ($month, $actor): MonthlyPeriodRecord {
                $existing = MonthlyPeriodRecord::query()
                    ->where('period_month', $month->startsOn())
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw PeriodAlreadyExists::forMonth($month);
                }

                $period = MonthlyPeriodRecord::query()->create([
                    'period_month' => $month->startsOn(),
                    'status' => 'open',
                    'opened_at' => now(),
                    'opened_by' => $actor->id,
                ]);

                event(new PeriodCreated($period, $actor));

                return $period->refresh();
            });
        } catch (QueryException $e) {
            // The race. Another request inserted this month between the check above and
            // the insert below, which the lock could not prevent because there was no row
            // to lock.
            //
            // Reported as the conflict it is. An operator who pressed the button twice, or
            // who pressed it while a colleague did the same, gets "October already exists"
            // — which is true, and actionable — instead of a 500 they cannot interpret.
            if (! UniqueViolation::isFor($e, SchemaConstraint::PERIOD_MONTH)) {
                throw $e;
            }

            throw PeriodAlreadyExists::forMonth($month);
        }
    }
}

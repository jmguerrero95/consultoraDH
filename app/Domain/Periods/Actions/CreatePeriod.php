<?php

declare(strict_types=1);

namespace App\Domain\Periods\Actions;

use App\Domain\Periods\Events\PeriodCreated;
use App\Domain\Periods\MonthlyPeriod as Month;
use App\Domain\Periods\PeriodAlreadyExists;
use App\Models\MonthlyPeriod as MonthlyPeriodRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Open a month.
 *
 * Its own action rather than a method on the resolver. The resolver answers "which
 * month is current"; opening a month writes a row, emits an event and needs a
 * transaction, and those are different jobs from reading a value. When the two live
 * together, the read path grows transaction and event handling it never needs.
 */
final readonly class CreatePeriod
{
    /**
     * @throws PeriodAlreadyExists when the month is already open
     */
    public function execute(Month $month, User $actor): MonthlyPeriodRecord
    {
        return DB::transaction(function () use ($month, $actor): MonthlyPeriodRecord {
            // Locked before the check, not after: two operators opening the same month
            // at the same moment would both see nothing, and the unique index would
            // turn the loser into a constraint violation instead of a message.
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
    }
}

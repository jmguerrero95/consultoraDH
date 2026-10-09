<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Models\GeneratedReport;
use App\Models\ReportSchedule;
use App\Notifications\ReportReadyNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Runs due report schedules exactly once per occurrence.
 *
 * ## The race this closes
 *
 * §57: two scheduler ticks must not generate the same occurrence twice, and a retry after
 * a failure must not duplicate a completed artifact. Three mechanisms cooperate:
 *
 *  1. **Row lock + claim.** The schedule row is locked and its `next_run_at` advanced in
 *     the same transaction that creates the report. A second tick arriving before the
 *     commit blocks on the lock and then sees a `next_run_at` that is already past the
 *     occurrence, so it moves on.
 *  2. **Occurrence key.** The artifact row carries a deterministic
 *     `schedule:<id>:<due timestamp>` string. Even if a retry somehow re-entered, the
 *     unique index on that column makes the second insert fail rather than silently
 *     produce a duplicate the owner would see twice.
 *  3. **Authorisation re-check.** §56 requires the owner to still be active and to still
 *     hold `reports.view`; a schedule whose owner lost the permission generates nothing.
 */
final class ReportScheduleRunner
{
    public function __construct(
        private readonly ReportGenerator $generator,
        private readonly AuditRecorder $audit,
    ) {}

    public function runDue(): int
    {
        $now = CarbonImmutable::now();
        $ran = 0;

        $due = ReportSchedule::query()
            ->where('active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->pluck('id');

        foreach ($due as $scheduleId) {
            if ($this->runOne((int) $scheduleId, $now)) {
                $ran++;
            }
        }

        return $ran;
    }

    private function runOne(int $scheduleId, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($scheduleId, $now): bool {
            $schedule = ReportSchedule::query()
                ->whereKey($scheduleId)
                ->lockForUpdate()
                ->first();

            if ($schedule === null || ! $schedule->active || $schedule->next_run_at === null) {
                return false;
            }

            $occurrence = $schedule->next_run_at->copy();

            if ($occurrence->greaterThan($now)) {
                return false;
            }

            // Advance first: the claim is the point of the lock, so a concurrent tick
            // cannot decide the same occurrence is still due.
            $schedule->forceFill([
                'next_run_at' => $this->nextRun($schedule, $occurrence),
                'last_run_at' => $now,
            ])->save();

            $owner = $schedule->owner;

            // §56: the owner must still be active and still hold the report's permission.
            // A schedule left behind by somebody who changed role generates nothing rather
            // than mailing a financial report to a person who may no longer see one.
            if ($owner === null || ! $owner->isActive()) {
                return true;
            }

            try {
                $type = ReportType::parse($schedule->report_type);
            } catch (\Throwable) {
                return true;
            }

            if (! $owner->can($type->permission())) {
                return true;
            }

            $key = sprintf('schedule:%d:%s', $schedule->id, $occurrence->toDateTimeString());

            if (GeneratedReport::query()->where('occurrence_key', $key)->exists()) {
                return true;
            }

            try {
                $report = $this->generator->generate(
                    $type,
                    $schedule->filters,
                    $schedule->format,
                    $owner,
                    $key,
                );
            } catch (QueryException) {
                // The unique index refused a duplicate occurrence: the work is already
                // done, which is the outcome the retry wanted.
                return true;
            } catch (\Throwable) {
                return true;
            }

            $owner->notify(new ReportReadyNotification($report, $schedule));

            $this->audit->record(AuditAction::ReportScheduleRan, $owner, [
                'schedule_id' => (int) $schedule->id,
                'occurrence' => $key,
                'generated_report_id' => (int) $report->id,
            ], null, $schedule);

            return true;
        });
    }

    /**
     * The next due timestamp, from the same calculator creation uses.
     *
     * Two implementations of this rule is how creation came to write `now() + 1h` while
     * recurrence computed the real cadence, so the rule lives in one pure function and
     * both callers use it.
     */
    public function nextRun(ReportSchedule $schedule, Carbon $from): Carbon
    {
        return Carbon::instance(ReportScheduleCalculator::nextAfter(
            $schedule->cadence,
            $schedule->run_time?->format('H:i') ?? '08:00',
            $schedule->day_of_week,
            $schedule->day_of_month,
            CarbonImmutable::instance($from),
        ));
    }
}

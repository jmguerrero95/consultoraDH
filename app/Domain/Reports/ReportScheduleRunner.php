<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Models\GeneratedReport;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleRun;
use App\Notifications\ReportReadyNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs due report schedules, and keeps a durable record of every occurrence.
 *
 * ## What changed and why
 *
 * The previous runner locked the schedule, advanced `next_run_at`, generated the
 * report and caught `\Throwable` **inside the same transaction**. A swallowed
 * exception committed the advance, so the occurrence was lost: no artifact, no
 * notification, no failure to see, and the schedule looked healthy.
 *
 * Now the advance and the occurrence claim are one short transaction; generation
 * happens outside it. The occurrence row is the claim, with three states that
 * matter operationally:
 *
 *  - `succeeded` once the artifact exists;
 *  - `failed` with an error code and an attempt count, retried on later ticks
 *    up to {@see self::MAX_ATTEMPTS};
 *  - `blocked` when retrying cannot help (owner inactive or without permission,
 *    unknown report type).
 *
 * A run never reports success without an artifact. The occurrence key written on
 * the artifact also means a retry after a crash links to the report that already
 * exists instead of producing a second one.
 */
final class ReportScheduleRunner
{
    /** Attempts per occurrence. Three ticks of a transient storage failure. */
    public const MAX_ATTEMPTS = 3;

    /** Minutes before a failed occurrence is tried again. */
    public const RETRY_AFTER_MINUTES = 5;

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
            $occurrenceId = $this->claimOccurrence((int) $scheduleId, $now);

            if ($occurrenceId === null) {
                continue;
            }

            $run = ReportScheduleRun::query()->find($occurrenceId);

            if ($run !== null && $this->execute($run)) {
                $ran++;
            }
        }

        // Failed occurrences survive the advance of `next_run_at`; this is where
        // they come back. A successful or blocked occurrence is never retried.
        $retryable = ReportScheduleRun::query()
            ->whereIn('status', [ReportScheduleRun::STATUS_PENDING, ReportScheduleRun::STATUS_FAILED])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('updated_at', '<=', $now->subMinutes(self::RETRY_AFTER_MINUTES))
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($retryable as $run) {
            if ($this->execute($run)) {
                $ran++;
            }
        }

        return $ran;
    }

    /**
     * Claim one due occurrence and advance the schedule, atomically.
     *
     * Returns the occurrence id, or null when the schedule is no longer due
     * (another tick already moved it).
     */
    private function claimOccurrence(int $scheduleId, CarbonImmutable $now): ?int
    {
        return DB::transaction(function () use ($scheduleId, $now): ?int {
            $schedule = ReportSchedule::query()
                ->whereKey($scheduleId)
                ->lockForUpdate()
                ->first();

            if ($schedule === null || ! $schedule->active || $schedule->next_run_at === null) {
                return null;
            }

            $occurrence = CarbonImmutable::instance($schedule->next_run_at);

            if ($occurrence->greaterThan($now)) {
                return null;
            }

            $schedule->forceFill([
                'next_run_at' => $this->nextRun($schedule, Carbon::instance($occurrence)),
                'last_run_at' => $now,
            ])->save();

            $run = ReportScheduleRun::query()->firstOrCreate(
                [
                    'report_schedule_id' => $schedule->id,
                    'occurrence_key' => $occurrence->toDateTimeString(),
                ],
                [
                    'status' => ReportScheduleRun::STATUS_PENDING,
                    'attempts' => 0,
                ],
            );

            return (int) $run->id;
        });
    }

    /**
     * Execute one claimed occurrence. Returns false when another worker owns it
     * or it is already finished.
     */
    private function execute(ReportScheduleRun $run): bool
    {
        $claimed = ReportScheduleRun::query()
            ->whereKey($run->id)
            ->whereIn('status', [ReportScheduleRun::STATUS_PENDING, ReportScheduleRun::STATUS_FAILED])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->update([
                'status' => ReportScheduleRun::STATUS_RUNNING,
                'attempts' => DB::raw('attempts + 1'),
                'started_at' => now(),
                'finished_at' => null,
                'error_code' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $run->refresh();
        $schedule = $run->schedule;

        if ($schedule === null) {
            $this->finish($run, ReportScheduleRun::STATUS_BLOCKED, 'schedule_missing');

            return true;
        }

        $owner = $schedule->owner;

        // §56: an inactive or demoted owner generates nothing. Retrying will not
        // change that, so the occurrence is blocked rather than failed.
        if ($owner === null || ! $owner->isActive()) {
            $this->finish($run, ReportScheduleRun::STATUS_BLOCKED, 'owner_inactive');

            return true;
        }

        try {
            $type = ReportType::parse($schedule->report_type);
        } catch (\Throwable) {
            $this->finish($run, ReportScheduleRun::STATUS_BLOCKED, 'invalid_report_type');

            return true;
        }

        if (! $owner->can($type->permission())) {
            $this->finish($run, ReportScheduleRun::STATUS_BLOCKED, 'owner_permission_lost');

            return true;
        }

        $key = sprintf('schedule:%d:%s', $schedule->id, $run->occurrence_key);

        $existing = GeneratedReport::query()->where('occurrence_key', $key)->first();

        if ($existing !== null) {
            // A previous attempt produced the artifact but did not get to close
            // the occurrence (crash, notification failure). Do not build it again.
            $this->finish($run, ReportScheduleRun::STATUS_SUCCEEDED, null, (int) $existing->id);

            return true;
        }

        try {
            $report = $this->generator->generate(
                $type,
                $schedule->filters ?? [],
                $schedule->format,
                $owner,
                $key,
            );
        } catch (QueryException $e) {
            // The occurrence key collided: another writer won the same artifact.
            $existing = GeneratedReport::query()->where('occurrence_key', $key)->first();

            if ($existing !== null) {
                $this->finish($run, ReportScheduleRun::STATUS_SUCCEEDED, null, (int) $existing->id);

                return true;
            }

            $this->fail($run, $e);

            return true;
        } catch (\Throwable $e) {
            $this->fail($run, $e);

            return true;
        }

        $this->finish($run, ReportScheduleRun::STATUS_SUCCEEDED, null, (int) $report->id);

        try {
            $owner->notify(new ReportReadyNotification($report, $schedule));
        } catch (\Throwable $e) {
            // The report exists and is downloadable; failing the occurrence over
            // a notification would claim the work was lost. Log and move on.
            Log::warning('Report ready notification failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->audit->record(AuditAction::ReportScheduleRan, $owner, [
            'schedule_id' => (int) $schedule->id,
            'occurrence' => $key,
            'generated_report_id' => (int) $report->id,
        ], null, $schedule);

        return true;
    }

    private function fail(ReportScheduleRun $run, \Throwable $e): void
    {
        Log::error('Scheduled report generation failed', [
            'schedule_id' => $run->report_schedule_id,
            'occurrence' => $run->occurrence_key,
            'attempt' => $run->attempts,
            'error' => $e->getMessage(),
        ]);

        $this->finish(
            $run,
            ReportScheduleRun::STATUS_FAILED,
            mb_substr(class_basename($e), 0, 60),
        );
    }

    private function finish(
        ReportScheduleRun $run,
        string $status,
        ?string $errorCode = null,
        ?int $reportId = null,
    ): void {
        $attributes = [
            'status' => $status,
            'error_code' => $errorCode,
            'finished_at' => now(),
            'updated_at' => now(),
        ];

        if ($reportId !== null) {
            $attributes['generated_report_id'] = $reportId;
        }

        ReportScheduleRun::query()->whereKey($run->id)->update($attributes);

        $run->refresh();
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

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
use Illuminate\Support\Str;

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
 * happens outside it. The occurrence row is the claim, with five states that
 * matter operationally:
 *
 *  - `succeeded` once the artifact exists;
 *  - `failed` with an error code and an attempt count, retried on later ticks
 *    up to {@see self::MAX_ATTEMPTS};
 *  - `blocked` when retrying cannot help (owner inactive or without permission,
 *    unknown report type) or when the schedule was deactivated before the work
 *    could start;
 *  - `running` while a worker owns it, under a **lease**;
 *  - `pending` before anybody claimed it.
 *
 * ## The lease and the fence
 *
 * `running` used to be a dead end: only `pending` and `failed` were recovered,
 * so an occurrence whose executor died mid-generation stayed `running` for
 * ever — invisible, unretryable, and indistinguishable from a healthy run.
 *
 * Now a claim carries two things:
 *
 *  - `lease_expires_at`: the moment the claim stops being honoured. A
 *    `running` occurrence whose lease has expired is a claim nobody is
 *    honouring any more, and the next tick may take it over.
 *  - `claim_token`: the fence. The executor writes its outcome with
 *    `WHERE claim_token = :token`, so an executor whose lease expired while it
 *    was still working cannot overwrite the result of the executor that took
 *    the occurrence over, and cannot send a second notification.
 *
 * The lease length is configurable (`REPORT_SCHEDULE_LEASE_SECONDS`) and has to
 * be longer than the longest legitimate generation. The fence is what makes the
 * recovery safe even when it was not.
 *
 * ## Deactivating a schedule
 *
 * Deactivating a schedule stops new work from starting for it. Occurrences that
 * were already finished keep their history; occurrences that are still pending
 * or failed are **blocked** with the reason recorded, so nothing is generated
 * while the schedule is off and re-activating it does not silently revive work
 * that was cancelled. An occurrence that was already being worked on is not
 * interrupted: a write that is half done is worse than one that finishes.
 */
final class ReportScheduleRunner
{
    /** Attempts per occurrence. Three ticks of a transient storage failure. */
    public const MAX_ATTEMPTS = 3;

    /** Minutes before a failed occurrence is tried again. */
    public const RETRY_AFTER_MINUTES = 5;

    /**
     * Fallback when the configuration is missing (a cached configuration from
     * before this key existed, for instance).
     */
    public const DEFAULT_LEASE_SECONDS = 1800;

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

        // A05-REP-02: the claims nobody is honouring any more. An occurrence
        // whose executor died stays `running` until its lease expires, and
        // then it is taken over. The attempts already spent are counted, so a
        // worker that keeps dying cannot retry for ever.
        $abandoned = ReportScheduleRun::query()
            ->where('status', ReportScheduleRun::STATUS_RUNNING)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', $now)
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($abandoned as $run) {
            if ($run->attempts >= self::MAX_ATTEMPTS) {
                // The attempt budget is gone: the occurrence ends in a
                // definitive error rather than staying `running` for ever.
                $this->finish(
                    $run,
                    ReportScheduleRun::STATUS_FAILED,
                    'attempts_exhausted',
                    null,
                    $run->claim_token,
                );

                continue;
            }

            if ($this->execute($run)) {
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
            // A05-REP-03: a deactivated schedule starts no new work. The
            // occurrence is blocked with the reason recorded, so the history
            // says why nothing was generated and re-activating the schedule
            // does not revive it silently.
            if ($this->isDeactivated($run)) {
                $this->block($run, 'schedule_inactive');

                continue;
            }

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
     * Whether the schedule of this occurrence is switched off.
     */
    private function isDeactivated(ReportScheduleRun $run): bool
    {
        $schedule = $run->schedule;

        return $schedule !== null && ! $schedule->active;
    }

    /**
     * Execute one claimed occurrence. Returns false when another worker owns it
     * or it is already finished.
     */
    private function execute(ReportScheduleRun $run): bool
    {
        $token = $this->claim($run);

        if ($token === null) {
            return false;
        }

        $run->refresh();
        $schedule = $run->schedule;

        if ($schedule === null) {
            $this->finish($run, ReportScheduleRun::STATUS_BLOCKED, 'schedule_missing', null, $token);

            return true;
        }

        $owner = $schedule->owner;

        // §56: an inactive or demoted owner generates nothing. Retrying will not
        // change that, so the occurrence is blocked rather than failed.
        if ($owner === null || ! $owner->isActive()) {
            $this->finish($run, ReportScheduleRun::STATUS_BLOCKED, 'owner_inactive', null, $token);

            return true;
        }

        try {
            $type = ReportType::parse($schedule->report_type);
        } catch (\Throwable) {
            $this->finish($run, ReportScheduleRun::STATUS_BLOCKED, 'invalid_report_type', null, $token);

            return true;
        }

        if (! $owner->can($type->permission())) {
            $this->finish($run, ReportScheduleRun::STATUS_BLOCKED, 'owner_permission_lost', null, $token);

            return true;
        }

        $key = sprintf('schedule:%d:%s', $schedule->id, $run->occurrence_key);

        $existing = GeneratedReport::query()->where('occurrence_key', $key)->first();

        if ($existing !== null) {
            // A previous attempt produced the artifact but did not get to close
            // the occurrence (crash, notification failure). Do not build it again.
            $this->finish($run, ReportScheduleRun::STATUS_SUCCEEDED, null, (int) $existing->id, $token);

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
                $this->finish($run, ReportScheduleRun::STATUS_SUCCEEDED, null, (int) $existing->id, $token);

                return true;
            }

            $this->fail($run, $e, $token);

            return true;
        } catch (\Throwable $e) {
            $this->fail($run, $e, $token);

            return true;
        }

        // The fence: if this executor lost the occurrence while it was
        // working, the write below changes nothing and no second notification
        // goes out.
        if (! $this->finish($run, ReportScheduleRun::STATUS_SUCCEEDED, null, (int) $report->id, $token)) {
            return true;
        }

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

    /**
     * Take ownership of an occurrence, under a lease and a fresh fence.
     *
     * The claim succeeds only on an occurrence that nobody owns — `pending`, a
     * failed one, or a `running` one whose lease has expired. The lease is
     * renewed here, and the token is replaced, which is what fences the
     * previous owner out.
     *
     * @return string|null the claim token, or null when somebody else owns it
     */
    private function claim(ReportScheduleRun $run): ?string
    {
        $token = (string) Str::uuid();

        $claimed = ReportScheduleRun::query()
            ->whereKey($run->id)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(function ($query): void {
                $query->whereIn('status', [ReportScheduleRun::STATUS_PENDING, ReportScheduleRun::STATUS_FAILED])
                    ->orWhere(function ($expired): void {
                        $expired->where('status', ReportScheduleRun::STATUS_RUNNING)
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<=', now());
                    });
            })
            ->update([
                'status' => ReportScheduleRun::STATUS_RUNNING,
                'attempts' => DB::raw('attempts + 1'),
                'started_at' => now(),
                'finished_at' => null,
                'error_code' => null,
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'claim_token' => $token,
                'updated_at' => now(),
            ]);

        return $claimed === 1 ? $token : null;
    }

    /**
     * How long a claim stays valid.
     *
     * The lease has to outlast the longest legitimate generation, and the
     * configuration says so. The fence, not the lease, is what makes the
     * recovery safe.
     */
    public function leaseSeconds(): int
    {
        $configured = (int) config('reports.schedule_lease_seconds');

        return $configured > 0 ? $configured : self::DEFAULT_LEASE_SECONDS;
    }

    /**
     * Block an occurrence that must not run, with the reason recorded.
     */
    private function block(ReportScheduleRun $run, string $errorCode): void
    {
        ReportScheduleRun::query()
            ->whereKey($run->id)
            ->whereIn('status', [ReportScheduleRun::STATUS_PENDING, ReportScheduleRun::STATUS_FAILED])
            ->update([
                'status' => ReportScheduleRun::STATUS_BLOCKED,
                'error_code' => $errorCode,
                'finished_at' => now(),
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function fail(ReportScheduleRun $run, \Throwable $e, ?string $token = null): void
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
            null,
            $token,
        );
    }

    /**
     * Write the outcome of a claimed occurrence.
     *
     * The fence lives here: when a token is given, the write only applies to
     * the claim that token owns. An executor whose lease expired while it was
     * working therefore changes nothing.
     *
     * @return bool whether this executor still owned the occurrence
     */
    private function finish(
        ReportScheduleRun $run,
        string $status,
        ?string $errorCode = null,
        ?int $reportId = null,
        ?string $token = null,
    ): bool {
        $attributes = [
            'status' => $status,
            'error_code' => $errorCode,
            'finished_at' => now(),
            'lease_expires_at' => null,
            'updated_at' => now(),
        ];

        if ($reportId !== null) {
            $attributes['generated_report_id'] = $reportId;
        }

        $query = ReportScheduleRun::query()->whereKey($run->id);

        if ($token !== null) {
            $query->where('claim_token', $token);
        }

        $updated = $query->update($attributes);

        if ($updated === 1) {
            $run->refresh();
        }

        return $updated === 1;
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

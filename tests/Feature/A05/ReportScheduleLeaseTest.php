<?php

declare(strict_types=1);

use App\Domain\Reports\ReportFormat;
use App\Domain\Reports\ReportGenerator;
use App\Domain\Reports\ReportScheduleRunner;
use App\Domain\Reports\ReportType;
use App\Models\GeneratedReport;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SecondConnection;

/*
|--------------------------------------------------------------------------
| A05-REP-02/03/04 — a scheduled occurrence that is abandoned, cancelled or
| half-written must not disappear, must not be duplicated and must not leave
| an orphan file behind.
|--------------------------------------------------------------------------
|
| The runner used to recover only `pending` and `failed`: an occurrence whose
| executor died mid-generation stayed `running` for ever. It also retried
| pending work of a schedule that had been switched off, and it had no fence,
| so an executor that came back late could overwrite the result of the worker
| that had taken its place.
|
| The crash is not simulated with an exception. The state an abruptly
| terminated executor leaves behind is written by a **second PostgreSQL
| session** and committed there, so the recovery is asked of a claim that a
| different process really left behind.
|
*/

beforeEach(function (): void {
    seedPortfolioRoles();
    Storage::fake('local');
    Notification::fake();

    $this->operator = userWithPermissions([
        'reports.view',
        'reports.export',
        'reports.schedule',
        'planillas.view',
    ]);

    $this->second = SecondConnection::forTestingConfig(config('database.connections.testing'));

    // The rows this file commits from the second session have to be removed
    // **after** the framework has rolled the main transaction back: while that
    // transaction is open, the rows it locked cannot be deleted from another
    // session, and the delete would wait for ever.
    //
    // `beforeApplicationDestroyed` callbacks run in registration order, and the
    // rollback is registered first, in `setUp`.
    $this->beforeApplicationDestroyed(function (): void {
        foreach (['report_schedule_runs', 'report_schedules'] as $table) {
            $this->second->execute('DELETE FROM '.$table);
        }

        $this->second->execute("DELETE FROM users WHERE email = 'caido@consultora-dh.test'");

        $this->second->disconnect();
    });
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A schedule whose generation always fails, so the occurrence stays pending
 * for the test to inspect.
 */
function rep05_failingSchedule(array $attributes = []): ReportSchedule
{
    return ReportSchedule::factory()->create(array_merge([
        'report_type' => ReportType::LibroOperativo->value,
        'format' => ReportFormat::Xlsx->value,
        // Outside the accepted range, so the first attempt genuinely throws.
        'filters' => ['year' => '1999', 'months' => '1'],
        'active' => true,
        'next_run_at' => now()->subMinute(),
    ], $attributes));
}

/**
 * A schedule that generates normally.
 */
function rep05_workingSchedule(array $attributes = []): ReportSchedule
{
    return ReportSchedule::factory()->create(array_merge([
        'report_type' => ReportType::Morosos->value,
        'format' => ReportFormat::Pdf->value,
        'filters' => [],
        'active' => true,
        'next_run_at' => now()->subMinute(),
    ], $attributes));
}

it('records the claim of an occurrence with a lease and a fence', function (): void {
    $schedule = rep05_workingSchedule(['owner_user_id' => $this->operator->id]);

    // The state of the occurrence is read at the moment the artifact is being
    // written, which is inside the claim. What the reader sees is what the
    // claim really wrote.
    $observed = null;

    DB::listen(function ($query) use (&$observed): void {
        if (! str_contains($query->sql, 'generated_reports') || $observed !== null) {
            return;
        }

        $row = ReportScheduleRun::query()->first();

        if ($row !== null) {
            $observed = [
                'status' => $row->status,
                'attempts' => $row->attempts,
                'lease_expires_at' => $row->lease_expires_at,
                'claim_token' => $row->claim_token,
            ];
        }
    });

    app(ReportScheduleRunner::class)->runDue();

    expect($observed)->not->toBeNull()
        ->and($observed['status'])->toBe(ReportScheduleRun::STATUS_RUNNING)
        ->and($observed['attempts'])->toBe(1)
        ->and($observed['lease_expires_at'])->not->toBeNull()
        ->and($observed['claim_token'])->not->toBeNull();
});

it('recovers an occurrence whose executor died, from another session', function (): void {
    // The crashed executor: another PostgreSQL session creates the owner, the
    // schedule and the claim, and commits them. It never writes the outcome,
    // which is exactly what an abruptly terminated process leaves behind.
    //
    // The owner is created in the second session because the schedule's
    // foreign key has to be satisfied by a row that session can see: a row
    // that only exists inside this test's uncommitted transaction is invisible
    // to it.
    $ownerId = $this->second->select(
        "INSERT INTO users (name, email, password, status, account_type, created_at, updated_at)
         VALUES
            ('Propietario caído', 'caido@consultora-dh.test', 'x', 'active', 'staff', now(), now())
         RETURNING id",
    )[0]['id'];

    $this->second->commit();

    // The role is granted from this connection, where the seeded matrix is
    // visible.
    $owner = User::query()->findOrFail($ownerId);
    $owner->assignRole('Administrator');

    $scheduleId = $this->second->select(
        "INSERT INTO report_schedules
            (name, report_type, filters, format, cadence, run_time, day_of_week, day_of_month,
             owner_user_id, active, next_run_at, created_at, updated_at)
         VALUES
            ('Programada caída', 'morosos', '{}'::jsonb, 'pdf', 'weekly', '08:00:00', 1, NULL,
             ?, TRUE, ?, ?, ?)
         RETURNING id",
        [
            $ownerId,
            now()->addWeek()->format('Y-m-d H:i:s'),
            now()->format('Y-m-d H:i:s'),
            now()->format('Y-m-d H:i:s'),
        ],
    )[0]['id'];

    // The timestamps travel from PHP, not from the database's `now()`: the
    // server's clock is in UTC and the column has no time zone, so a value
    // written by PostgreSQL would be read back five hours away.
    $occurrenceId = $this->second->select(
        "INSERT INTO report_schedule_runs
            (report_schedule_id, occurrence_key, status, attempts, lease_expires_at, claim_token,
             started_at, created_at, updated_at)
         VALUES
            (?, '2026-01-01 08:00:00', 'running', 1, ?, '99999999-8888-7777-6666-555555555555',
             ?, ?, ?)
         RETURNING id",
        [
            $scheduleId,
            now()->addSeconds(app(ReportScheduleRunner::class)->leaseSeconds())->format('Y-m-d H:i:s'),
            now()->subMinutes(2)->format('Y-m-d H:i:s'),
            now()->format('Y-m-d H:i:s'),
            now()->format('Y-m-d H:i:s'),
        ],
    )[0]['id'];

    $this->second->commit();

    $runner = app(ReportScheduleRunner::class);

    // The claim is still inside its lease: nobody takes it over yet.
    expect($runner->runDue())->toBe(0);

    $row = ReportScheduleRun::query()->find($occurrenceId);

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe(ReportScheduleRun::STATUS_RUNNING)
        ->and($row->attempts)->toBe(1);

    // The lease expires: the occurrence is recovered, and the artifact of the
    // interrupted attempt is not built a second time.
    Carbon::setTestNow(now()->addSeconds($runner->leaseSeconds() + 60));

    expect($runner->runDue())->toBe(1);

    $row = ReportScheduleRun::query()->find($occurrenceId);

    expect($row->status)->toBe(ReportScheduleRun::STATUS_SUCCEEDED)
        ->and($row->attempts)->toBe(2)
        ->and($row->generated_report_id)->not->toBeNull()
        ->and(GeneratedReport::query()->count())->toBe(1);
});

it('does not let the executor that lost the occurrence overwrite the result', function (): void {
    $schedule = rep05_failingSchedule(['owner_user_id' => $this->operator->id]);

    $runner = app(ReportScheduleRunner::class);

    $runner->runDue();

    $occurrence = ReportScheduleRun::query()->sole();
    $staleToken = $occurrence->claim_token;

    // The lease expires and another worker takes the occurrence over.
    Carbon::setTestNow(now()->addSeconds($runner->leaseSeconds() + 60));
    $runner->runDue();

    $recovered = ReportScheduleRun::query()->sole();

    expect($recovered->claim_token)->not->toBe($staleToken);

    // The stale executor comes back and tries to close the occurrence with its
    // own token. The fence refuses it: this is the runner's own write, not a
    // hand-written UPDATE.
    $finish = new ReflectionMethod($runner, 'finish');
    $finish->setAccessible(true);

    $applied = $finish->invoke(
        $runner,
        $recovered,
        ReportScheduleRun::STATUS_SUCCEEDED,
        null,
        999999,
        $staleToken,
    );

    expect($applied)->toBeFalse()
        ->and(ReportScheduleRun::query()->sole()->status)->toBe(ReportScheduleRun::STATUS_FAILED);
});

it('does not recover the same occurrence twice when two workers race', function (): void {
    $schedule = rep05_failingSchedule(['owner_user_id' => $this->operator->id]);

    $runner = app(ReportScheduleRunner::class);

    $runner->runDue();

    $occurrence = ReportScheduleRun::query()->sole();

    Carbon::setTestNow(now()->addSeconds($runner->leaseSeconds() + 60));

    // Two workers, one tick each: the second one finds the occurrence already
    // claimed by the first and leaves it alone.
    $first = $runner->runDue();
    $second = $runner->runDue();

    expect($first + $second)->toBe(1)
        ->and(ReportScheduleRun::query()->count())->toBe(1)
        ->and(ReportScheduleRun::query()->sole()->attempts)->toBe(2);
});

it('stops recovering an occurrence once the attempt budget is spent', function (): void {
    $schedule = rep05_failingSchedule(['owner_user_id' => $this->operator->id]);

    $runner = app(ReportScheduleRunner::class);

    for ($i = 0; $i < ReportScheduleRunner::MAX_ATTEMPTS + 2; $i++) {
        $runner->runDue();
        Carbon::setTestNow(now()->addMinutes(ReportScheduleRunner::RETRY_AFTER_MINUTES + 1));
    }

    $occurrence = ReportScheduleRun::query()->sole();

    expect($occurrence->status)->toBe(ReportScheduleRun::STATUS_FAILED)
        ->and($occurrence->attempts)->toBe(ReportScheduleRunner::MAX_ATTEMPTS)
        ->and($occurrence->error_code)->not->toBeNull()
        ->and(GeneratedReport::query()->count())->toBe(0);
});

it('ends an abandoned occurrence in a definitive error when the attempts are gone', function (): void {
    $schedule = rep05_failingSchedule(['owner_user_id' => $this->operator->id]);

    $runner = app(ReportScheduleRunner::class);

    // A claim that already used the whole budget and then lost its executor.
    ReportScheduleRun::query()->create([
        'report_schedule_id' => $schedule->id,
        'occurrence_key' => '2026-01-01 08:00:00',
        'status' => ReportScheduleRun::STATUS_RUNNING,
        'attempts' => ReportScheduleRunner::MAX_ATTEMPTS,
        'lease_expires_at' => now()->subMinute(),
        'claim_token' => '11111111-2222-3333-4444-555555555555',
    ]);

    $runner->runDue();

    $occurrence = ReportScheduleRun::query()
        ->where('occurrence_key', '2026-01-01 08:00:00')
        ->sole();

    expect($occurrence->status)->toBe(ReportScheduleRun::STATUS_FAILED)
        ->and($occurrence->error_code)->toBe('attempts_exhausted');
});

it('reuses an artifact that already exists instead of generating a second one', function (): void {
    $schedule = rep05_workingSchedule(['owner_user_id' => $this->operator->id]);

    $runner = app(ReportScheduleRunner::class);

    $runner->runDue();

    $occurrence = ReportScheduleRun::query()->sole();

    expect($occurrence->status)->toBe(ReportScheduleRun::STATUS_SUCCEEDED)
        ->and(GeneratedReport::query()->count())->toBe(1);

    $report = GeneratedReport::query()->sole();

    // The occurrence is closed, but its artifact is still there. A second
    // attempt must link to it rather than build another one.
    $occurrence->forceFill([
        'status' => ReportScheduleRun::STATUS_FAILED,
        'error_code' => 'interrupted',
        'attempts' => 1,
        'updated_at' => now()->subMinutes(ReportScheduleRunner::RETRY_AFTER_MINUTES + 1),
    ])->save();

    Carbon::setTestNow(now()->addMinutes(ReportScheduleRunner::RETRY_AFTER_MINUTES + 1));

    expect($runner->runDue())->toBe(1)
        ->and(GeneratedReport::query()->count())->toBe(1)
        ->and($occurrence->fresh()->generated_report_id)->toBe($report->id)
        ->and($occurrence->fresh()->status)->toBe(ReportScheduleRun::STATUS_SUCCEEDED);
});

it('does not generate anything for a schedule that was deactivated', function (): void {
    $schedule = rep05_failingSchedule(['owner_user_id' => $this->operator->id]);

    $runner = app(ReportScheduleRunner::class);

    // The occurrence fails once.
    $runner->runDue();

    $occurrence = ReportScheduleRun::query()->sole();

    expect($occurrence->status)->toBe(ReportScheduleRun::STATUS_FAILED);

    // The operator switches the schedule off.
    $schedule->forceFill(['active' => false])->save();

    Carbon::setTestNow(now()->addMinutes(ReportScheduleRunner::RETRY_AFTER_MINUTES + 1));

    expect($runner->runDue())->toBe(0);

    $occurrence->refresh();

    // The history says why nothing was generated.
    expect($occurrence->status)->toBe(ReportScheduleRun::STATUS_BLOCKED)
        ->and($occurrence->error_code)->toBe('schedule_inactive')
        ->and(GeneratedReport::query()->count())->toBe(0);

    // Re-activating the schedule does not silently revive the cancelled work.
    $schedule->forceFill(['active' => true])->save();

    Carbon::setTestNow(now()->addMinutes(ReportScheduleRunner::RETRY_AFTER_MINUTES + 1));

    expect($runner->runDue())->toBe(0)
        ->and(ReportScheduleRun::query()->sole()->status)->toBe(ReportScheduleRun::STATUS_BLOCKED)
        ->and(GeneratedReport::query()->count())->toBe(0);
});

it('does not claim a new occurrence for a deactivated schedule', function (): void {
    $schedule = ReportSchedule::factory()->create([
        'owner_user_id' => $this->operator->id,
        'report_type' => ReportType::Morosos->value,
        'format' => ReportFormat::Pdf->value,
        'filters' => [],
        'active' => false,
        'next_run_at' => now()->subMinute(),
    ]);

    expect(app(ReportScheduleRunner::class)->runDue())->toBe(0)
        ->and(ReportScheduleRun::query()->count())->toBe(0)
        ->and(GeneratedReport::query()->count())->toBe(0);
});

it('leaves no orphan file when the report row cannot be written', function (): void {
    $ghost = new User;
    $ghost->forceFill(['id' => 999999, 'name' => 'Fantasma', 'account_type' => 'staff']);

    expect(fn () => app(ReportGenerator::class)->generate(
        ReportType::Morosos,
        [],
        ReportFormat::Pdf,
        $ghost,
    ))->toThrow(QueryException::class);

    // The failed INSERT aborts the surrounding test transaction, so only the
    // filesystem is inspected here. What matters is that the bytes written
    // before the row are gone.
    expect(Storage::disk('local')->allFiles('reports'))->toBe([]);
});

it('leaves no orphan file when the storage refuses the write', function (): void {
    // The storage refuses the directory: the file cannot be written, and the
    // report must fail instead of being registered as a zero-byte artifact.
    $root = Storage::disk('local')->path('');
    $directory = $root.'reports/'.now()->format('Y/m');

    mkdir($root.'/reports/'.now()->format('Y'), 0o750, true);
    file_put_contents($directory, 'esto es un archivo, no un directorio');

    try {
        expect(fn () => app(ReportGenerator::class)->generate(
            ReportType::Morosos,
            [],
            ReportFormat::Pdf,
            $this->operator,
        ))->toThrow(RuntimeException::class);

        expect(GeneratedReport::query()->count())->toBe(0)
            // Only the obstacle this test put there; no report was written.
            ->and(Storage::disk('local')->allFiles('reports'))
            ->toBe(['reports/'.now()->format('Y/m')]);
    } finally {
        @unlink($directory);
    }
});

it('serves a scheduled artifact through an authorised download', function (): void {
    $schedule = rep05_workingSchedule(['owner_user_id' => $this->operator->id]);

    $runner = app(ReportScheduleRunner::class);

    $runner->runDue();

    $report = GeneratedReport::query()->sole();

    $this->actingAs($this->operator)
        ->getJson("/api/reports/generated/{$report->id}/download")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

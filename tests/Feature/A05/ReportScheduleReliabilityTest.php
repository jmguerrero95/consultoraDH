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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * T01/T09 — a scheduled occurrence that fails is not lost, and a failed write
 * leaves no orphan bytes.
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
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a generation failure leaves a failed occurrence and is retried later', function (): void {
    $schedule = ReportSchedule::factory()->create([
        'owner_user_id' => $this->operator->id,
        'report_type' => ReportType::LibroOperativo->value,
        'format' => ReportFormat::Xlsx->value,
        // Outside the accepted range, so the first attempt genuinely throws.
        'filters' => ['year' => '1999', 'months' => '1'],
        'active' => true,
        'next_run_at' => now()->subMinute(),
    ]);

    $runner = app(ReportScheduleRunner::class);

    expect($runner->runDue())->toBe(1);

    $occurrence = ReportScheduleRun::query()->sole();

    expect($occurrence->status)->toBe(ReportScheduleRun::STATUS_FAILED)
        ->and($occurrence->attempts)->toBe(1)
        ->and($occurrence->error_code)->not->toBeNull()
        ->and(GeneratedReport::query()->count())->toBe(0)
        // The advance is still recorded: the next cadence is not blocked by the
        // failure. The occurrence itself is what is recoverable.
        ->and($schedule->fresh()->next_run_at->isFuture())->toBeTrue();

    // Immediately afterwards: backoff, not a tight loop.
    expect($runner->runDue())->toBe(0);

    // The operator fixes the filter; the next tick retries the same occurrence.
    $schedule->update(['filters' => ['year' => '2026', 'months' => '1']]);
    Carbon::setTestNow(now()->addMinutes(ReportScheduleRunner::RETRY_AFTER_MINUTES + 1));

    expect($runner->runDue())->toBe(1);

    $occurrence->refresh();

    expect($occurrence->status)->toBe(ReportScheduleRun::STATUS_SUCCEEDED)
        ->and($occurrence->attempts)->toBe(2)
        ->and($occurrence->generated_report_id)->not->toBeNull()
        ->and(GeneratedReport::query()->count())->toBe(1);
});

test('a failed occurrence stops after the attempt limit instead of retrying for ever', function (): void {
    ReportSchedule::factory()->create([
        'owner_user_id' => $this->operator->id,
        'report_type' => ReportType::LibroOperativo->value,
        'format' => ReportFormat::Xlsx->value,
        'filters' => ['year' => '1999', 'months' => '1'],
        'active' => true,
        'next_run_at' => now()->subMinute(),
    ]);

    $runner = app(ReportScheduleRunner::class);

    for ($i = 0; $i < ReportScheduleRunner::MAX_ATTEMPTS + 2; $i++) {
        $runner->runDue();
        Carbon::setTestNow(now()->addMinutes(ReportScheduleRunner::RETRY_AFTER_MINUTES + 1));
    }

    $occurrence = ReportScheduleRun::query()->sole();

    expect($occurrence->status)->toBe(ReportScheduleRun::STATUS_FAILED)
        ->and($occurrence->attempts)->toBe(ReportScheduleRunner::MAX_ATTEMPTS)
        ->and(GeneratedReport::query()->count())->toBe(0);
});

test('an owner without the report permission blocks the occurrence without retrying', function (): void {
    $schedule = ReportSchedule::factory()->create([
        'owner_user_id' => $this->operator->id,
        'report_type' => ReportType::Morosos->value,
        'format' => ReportFormat::Pdf->value,
        'filters' => [],
        'active' => true,
        'next_run_at' => now()->subMinute(),
    ]);

    $this->operator->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    app(ReportScheduleRunner::class)->runDue();

    $occurrence = ReportScheduleRun::query()->sole();

    expect($occurrence->status)->toBe(ReportScheduleRun::STATUS_BLOCKED)
        ->and($occurrence->error_code)->toBe('owner_permission_lost')
        ->and(GeneratedReport::query()->count())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(ReportScheduleRunner::RETRY_AFTER_MINUTES + 1));

    expect(app(ReportScheduleRunner::class)->runDue())->toBe(0);
});

test('two scheduler ticks produce one occurrence and one artifact', function (): void {
    ReportSchedule::factory()->create([
        'owner_user_id' => $this->operator->id,
        'report_type' => ReportType::Morosos->value,
        'format' => ReportFormat::Pdf->value,
        'filters' => [],
        'active' => true,
        'next_run_at' => now()->subMinute(),
    ]);

    $runner = app(ReportScheduleRunner::class);

    expect($runner->runDue())->toBe(1)
        ->and($runner->runDue())->toBe(0)
        ->and(ReportScheduleRun::query()->count())->toBe(1)
        ->and(GeneratedReport::query()->count())->toBe(1);
});

test('a report row that cannot be written leaves no orphan file', function (): void {
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

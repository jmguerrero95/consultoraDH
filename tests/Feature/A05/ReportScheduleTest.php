<?php

declare(strict_types=1);

use App\Domain\Reports\ReportCadence;
use App\Domain\Reports\ReportScheduleCalculator;
use App\Models\ReportSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * A05-R1 §5 — the first run of a report schedule must match the schedule.
 *
 * The gap: creation wrote `next_run_at = now()->addHour()`, so a weekly Monday-07:00
 * schedule first ran an hour after it was saved, on whatever day that happened to be. The
 * cadence the user entered described only the occurrences *after* the first.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    $this->operator = userWithPermissions([
        'reports.view', 'reports.export', 'reports.schedule',
    ]);
});

/** Store the schedule through the real endpoint and return what was persisted. */
function crearProgramacion($test, array $payload): ReportSchedule
{
    $test->actingAs($test->operator)
        ->postJson('/api/report-schedules', $payload)
        ->assertCreated();

    return ReportSchedule::query()->latest('id')->firstOrFail();
}

it('R1: a daily schedule first runs at the time the user chose', function (): void {
    // Wednesday 10 March 2026, 12:00.
    Date::setTestNow('2026-03-10 12:00:00');

    $schedule = crearProgramacion($this, [
        'name' => 'Cartera diaria',
        'report_type' => 'morosos',
        'format' => 'pdf',
        'cadence' => 'daily',
        'run_time' => '07:30',
    ]);

    // 07:30 today has already passed, so the first run is tomorrow at 07:30.
    expect($schedule->next_run_at->format('Y-m-d H:i:s'))->toBe('2026-03-11 07:30:00');
});

it('R1: a daily schedule later today still runs today', function (): void {
    Date::setTestNow('2026-03-10 06:00:00');

    $schedule = crearProgramacion($this, [
        'name' => 'Cartera de la mañana',
        'report_type' => 'morosos',
        'format' => 'csv',
        'cadence' => 'daily',
        'run_time' => '07:30',
    ]);

    expect($schedule->next_run_at->format('Y-m-d H:i:s'))->toBe('2026-03-10 07:30:00');
});

it('R1: a weekly schedule first runs on the weekday the user chose', function (): void {
    // Wednesday 11 March 2026.
    Date::setTestNow('2026-03-11 12:00:00');

    // Monday is 1 with an ISO week starting Monday.
    $schedule = crearProgramacion($this, [
        'name' => 'Cartera del lunes',
        'report_type' => 'morosos',
        'format' => 'pdf',
        'cadence' => 'weekly',
        'run_time' => '07:00',
        'day_of_week' => 1,
    ]);

    // Monday the 9th has passed, so the first run is Monday the 16th.
    expect($schedule->next_run_at->format('Y-m-d H:i:s'))->toBe('2026-03-16 07:00:00');
});

it('R1: a weekly schedule earlier this week runs this week', function (): void {
    // Wednesday 11 March 2026, 06:00 — a Wednesday, so Monday of that week has passed.
    Date::setTestNow('2026-03-11 06:00:00');

    $schedule = crearProgramacion($this, [
        'name' => 'Cartera del lunes temprano',
        'report_type' => 'morosos',
        'format' => 'pdf',
        'cadence' => 'weekly',
        'run_time' => '07:00',
        'day_of_week' => 1,
    ]);

    // Sunday of the week is 2026-03-08, so Monday the 9th at 07:00 has passed; the first
    // run is the Monday after.
    expect($schedule->next_run_at->format('Y-m-d H:i:s'))->toBe('2026-03-16 07:00:00');
});

it('R1: a weekly schedule later today runs today', function (): void {
    // Wednesday 11 March 2026, early morning.
    Date::setTestNow('2026-03-11 06:00:00');

    $schedule = crearProgramacion($this, [
        'name' => 'Cartera del miércoles',
        'report_type' => 'morosos',
        'format' => 'pdf',
        'cadence' => 'weekly',
        'run_time' => '07:00',
        // Wednesday is `N = 3`.
        'day_of_week' => 3,
    ]);

    expect($schedule->next_run_at->format('Y-m-d H:i:s'))->toBe('2026-03-11 07:00:00');
});

it('R1: a monthly schedule first runs on the day the user chose', function (): void {
    Date::setTestNow('2026-03-10 12:00:00');

    $schedule = crearProgramacion($this, [
        'name' => 'Cartera mensual',
        'report_type' => 'morosos',
        'format' => 'xlsx',
        'cadence' => 'monthly',
        'run_time' => '06:00',
        'day_of_month' => 25,
    ]);

    expect($schedule->next_run_at->format('Y-m-d H:i:s'))->toBe('2026-03-25 06:00:00');
});

it('R1: a monthly schedule on a day the month lacks runs on that month\'s last day', function (): void {
    // The documented rule: day 29–31 in a shorter month lands on its last day.
    Date::setTestNow('2026-02-01 12:00:00');

    $schedule = crearProgramacion($this, [
        'name' => 'Cartera fin de mes',
        'report_type' => 'morosos',
        'format' => 'pdf',
        'cadence' => 'monthly',
        'run_time' => '06:00',
        'day_of_month' => 31,
    ]);

    // February 2026 has 28 days.
    expect($schedule->next_run_at->format('Y-m-d H:i:s'))->toBe('2026-02-28 06:00:00');
});

it('R1: a monthly schedule keeps its day of month across a short month', function (): void {
    $calculator = ReportScheduleCalculator::class;

    // From 31 January, the next monthly run is 28 February — not the 27th of March.
    $from = CarbonImmutable::parse('2026-01-31 06:00:00');

    $next = ReportScheduleCalculator::nextAfter(
        ReportCadence::Monthly,
        '06:00',
        null,
        31,
        $from,
    );

    expect($next->format('Y-m-d H:i:s'))->toBe('2026-02-28 06:00:00');

    // The second recurrence is the one that matters, because that is the first time the
    // previous run sits on a date the schedule never asked for. Carrying the clamped day
    // forward would settle on the 28th of every following month and never return to the
    // 31st it was configured for.
    $siguiente = ReportScheduleCalculator::nextAfter(
        ReportCadence::Monthly,
        '06:00',
        null,
        31,
        $next,
    );

    expect($siguiente->format('Y-m-d H:i:s'))->toBe('2026-03-31 06:00:00');

    // And a 29th keeps its meaning through a non-leap February rather than sticking at
    // the 28th, which is what a February-clamped run would otherwise do forever.
    $febrero = ReportScheduleCalculator::nextAfter(
        ReportCadence::Monthly,
        '06:00',
        null,
        29,
        CarbonImmutable::parse('2026-01-29 06:00:00'),
    );

    expect($febrero->format('Y-m-d H:i:s'))->toBe('2026-02-28 06:00:00');
    expect(
        ReportScheduleCalculator::nextAfter(ReportCadence::Monthly, '06:00', null, 29, $febrero)
            ->format('Y-m-d H:i:s'),
    )->toBe('2026-03-29 06:00:00');
});

it('R1: a cadence rejects a day field it requires and ignores the ones it does not', function (): void {
    $this->actingAs($this->operator)
        ->postJson('/api/report-schedules', [
            'name' => 'Semana incompleta',
            'report_type' => 'morosos',
            'format' => 'pdf',
            'cadence' => 'weekly',
            'run_time' => '07:00',
            // day_of_week omitted on purpose.
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'day_of_week');

    $this->actingAs($this->operator)
        ->postJson('/api/report-schedules', [
            'name' => 'Mes incompleto',
            'report_type' => 'morosos',
            'format' => 'pdf',
            'cadence' => 'monthly',
            'run_time' => '07:00',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'day_of_month');

    // A daily schedule ignores a day field rather than storing it unread.
    Date::setTestNow('2026-03-10 12:00:00');

    $this->actingAs($this->operator)
        ->postJson('/api/report-schedules', [
            'name' => 'Diaria con día de más',
            'report_type' => 'morosos',
            'format' => 'pdf',
            'cadence' => 'daily',
            'run_time' => '07:00',
            'day_of_week' => 3,
        ])
        ->assertCreated();

    $schedule = ReportSchedule::query()->latest('id')->firstOrFail();

    expect($schedule->day_of_week)->toBeNull()
        ->and($schedule->day_of_month)->toBeNull();
});

it('R1: a malformed run time is refused', function (): void {
    $this->actingAs($this->operator)
        ->postJson('/api/report-schedules', [
            'name' => 'Hora inválida',
            'report_type' => 'morosos',
            'format' => 'pdf',
            'cadence' => 'daily',
            'run_time' => '25:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('run_time');
});

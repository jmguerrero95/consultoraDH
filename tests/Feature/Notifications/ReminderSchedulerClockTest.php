<?php

declare(strict_types=1);

use App\Domain\Operations\TaskStatus;
use App\Models\OperationalTask;
use App\Notifications\TaskReminderNotification;
use App\Services\WebPushService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

/**
 * The scheduler, as an occurrence rather than as a listing.
 *
 * `schedule:list` only prints the expression. What has to be true is that the
 * command, run at minute 09:01 under a controlled clock, sends the reminder;
 * that it does not send it again four minutes later; and that the technical
 * retry happens exactly once five minutes after the attempt it retries.
 *
 * The real process is `consultora-dh-scheduler` running
 * `php artisan schedule:work --verbose`, whose log shows the command at
 * 11:17:00 and 11:18:00. This test covers the other half: what one of those
 * occurrences does.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    Notification::fake();
});

function clockTask(int $assigneeId): OperationalTask
{
    return OperationalTask::factory()->create([
        'assigned_to' => $assigneeId,
        'status' => TaskStatus::Pending,
        'reminder_at' => now()->subMinute(),
        'reminder_sent_at' => null,
        'reminder_received_at' => null,
        'reminder_dismissed_at' => null,
        'reminder_second_sent_at' => null,
        'reminder_attempts' => 0,
    ]);
}

it('is scheduled every minute rather than every five', function (): void {
    // The definition is registered when the console application boots; a bare
    // `app(Schedule::class)` before that is an empty instance. `schedule:list`
    // is only used to reach that state — the assertion below is about the
    // event itself, and the occurrence is proved by the test that follows.
    Artisan::call('schedule:list');

    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn (object $event): bool => str_contains((string) $event->command, 'operations:dispatch-reminders'),
    );

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

it('sends the first reminder at minute 09:01 and retries once five minutes later', function (): void {
    $operator = userWithPermissions(['tasks.view', 'tasks.manage']);

    test()->mock(WebPushService::class, function ($mock): void {
        $mock->shouldReceive('sendNotification')->times(2);
    });

    // The clock is set before the task exists, so `reminder_at` lands before
    // the first occurrence and the task is genuinely due at 09:01.
    $this->travelTo(Carbon::parse('2026-10-25 09:00:00'));

    $task = clockTask($operator->id);

    // 09:01: the first occurrence after `reminder_at`. The event runs on the
    // minute, so a task due at 09:01 is not waited on until 09:05.
    $this->travelTo(Carbon::parse('2026-10-25 09:01:00'));
    Artisan::call('operations:dispatch-reminders');

    expect($task->fresh()->reminder_sent_at?->format('Y-m-d H:i'))->toBe('2026-10-25 09:01');
    Notification::assertSentToTimes($operator, TaskReminderNotification::class, 1);

    // 09:05: only four minutes of technical silence have passed.
    $this->travelTo(Carbon::parse('2026-10-25 09:05:00'));
    Artisan::call('operations:dispatch-reminders');

    Notification::assertSentToTimes($operator, TaskReminderNotification::class, 1);

    // 09:06: the retry, and only it.
    $this->travelTo(Carbon::parse('2026-10-25 09:06:00'));
    Artisan::call('operations:dispatch-reminders');

    expect($task->fresh()->reminder_attempts)->toBe(1);

    Notification::assertSentToTimes($operator, TaskReminderNotification::class, 2);

    // Same minute: the occurrence is idempotent.
    Artisan::call('operations:dispatch-reminders');

    Notification::assertSentToTimes($operator, TaskReminderNotification::class, 2);

    $this->travelTo(Carbon::parse('2026-10-25 09:07:00'));
    Artisan::call('operations:dispatch-reminders');

    Notification::assertSentToTimes($operator, TaskReminderNotification::class, 2);
});

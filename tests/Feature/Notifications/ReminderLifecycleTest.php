<?php

declare(strict_types=1);

use App\Domain\Operations\ReminderDispatcher;
use App\Domain\Operations\TaskStatus;
use App\Models\OperationalTask;
use App\Notifications\TaskReminderNotification;
use App\Services\WebPushService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * The reminder sequence, as three provable moments.
 *
 * The scheduler runs every minute so the first reminder is not rounded up to
 * the next five-minute boundary. The five-minute interval belongs to the
 * technical retry and is enforced here, not by cron. The second reminder is
 * sent once and only after the device acknowledged the first, and a dismissal
 * ends the sequence.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    $this->operator = userWithPermissions(['tasks.view', 'tasks.manage']);

    Notification::fake();

    $this->travelTo(Carbon::parse('2026-10-24 09:00:00', 'UTC'));
});

function dueTask(int $assigneeId, array $overrides = []): OperationalTask
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
        ...$overrides,
    ]);
}

function fakePush(int $times): void
{
    test()->mock(WebPushService::class, function ($mock) use ($times): void {
        $mock->shouldReceive('sendNotification')->times($times);
    });
}

it('sends the initial reminder at minute resolution and does not repeat it within five minutes', function (): void {
    fakePush(1);

    $task = dueTask($this->operator->id);

    $dispatcher = app(ReminderDispatcher::class);

    expect($dispatcher->dispatchDue())->toBe(1);

    $task->refresh();

    expect($task->reminder_sent_at)->not->toBeNull()
        ->and($task->reminder_attempts)->toBe(0)
        ->and($task->reminder_received_at)->toBeNull();

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);

    // Same minute again: the reminder was sent and is not yet due a retry.
    $this->travelTo(Carbon::parse('2026-10-24 09:04:00', 'UTC'));

    expect($dispatcher->dispatchDue())->toBe(0);

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);
});

it('retries technically every five minutes while the device has not acknowledged', function (): void {
    fakePush(1);

    $task = dueTask($this->operator->id, [
        'reminder_sent_at' => now()->subMinutes(4),
    ]);

    $dispatcher = app(ReminderDispatcher::class);

    // Four minutes later: not a retry yet.
    expect($dispatcher->dispatchDue())->toBe(0);

    // Five minutes after the last attempt: one technical retry.
    $this->travelTo(Carbon::parse('2026-10-24 09:00:00', 'UTC')->addMinutes(5));

    expect($dispatcher->dispatchDue())->toBe(1);

    $task->refresh();

    expect($task->reminder_attempts)->toBe(1)
        ->and($task->reminder_received_at)->toBeNull();

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);

    // A second run in the same minute is not a second retry.
    expect($dispatcher->dispatchDue())->toBe(0);
});

it('sends the second reminder once, ten minutes after the acknowledgement', function (): void {
    fakePush(1);

    $task = dueTask($this->operator->id, [
        'reminder_sent_at' => now()->subMinutes(30),
        'reminder_received_at' => now()->subMinutes(11),
    ]);

    $dispatcher = app(ReminderDispatcher::class);

    expect($dispatcher->dispatchDue())->toBe(1);

    $task->refresh();

    expect($task->reminder_second_sent_at)->not->toBeNull();

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);

    // Ten more minutes: the second reminder is not sent again.
    $this->travelTo(Carbon::parse('2026-10-24 09:20:00', 'UTC'));

    expect($dispatcher->dispatchDue())->toBe(0);

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);
});

it('does not send the second reminder before ten minutes have passed', function (): void {
    fakePush(0);

    dueTask($this->operator->id, [
        'reminder_sent_at' => now()->subMinutes(20),
        'reminder_received_at' => now()->subMinutes(9),
    ]);

    expect(app(ReminderDispatcher::class)->dispatchDue())->toBe(0);
});

it('stops every reminder once the task was dismissed', function (): void {
    fakePush(0);

    dueTask($this->operator->id, [
        'reminder_sent_at' => now()->subMinutes(40),
        'reminder_received_at' => now()->subMinutes(30),
        'reminder_dismissed_at' => now()->subMinutes(29),
    ]);

    expect(app(ReminderDispatcher::class)->dispatchDue())->toBe(0);
    Notification::assertNothingSent();
});

it('stops reminders for a completed task', function (): void {
    fakePush(0);

    dueTask($this->operator->id, [
        'status' => TaskStatus::Done,
        'completed_at' => now()->subMinutes(45),
        'completed_by' => $this->operator->id,
        'reminder_sent_at' => now()->subMinutes(40),
        'reminder_received_at' => now()->subMinutes(30),
    ]);

    expect(app(ReminderDispatcher::class)->dispatchDue())->toBe(0);
    Notification::assertNothingSent();
});

it('skips a reminder when the assignee is inactive', function (): void {
    fakePush(0);

    $inactive = userWithPermissions(['tasks.view']);
    $inactive->forceFill(['status' => 'inactive'])->save();

    dueTask($inactive->id);

    app(ReminderDispatcher::class)->dispatchDue();

    Notification::assertNothingSent();
});

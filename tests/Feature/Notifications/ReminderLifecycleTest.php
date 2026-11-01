<?php

declare(strict_types=1);

use App\Domain\Operations\ReminderDispatcher;
use App\Domain\Operations\TaskStatus;
use App\Jobs\SendTelegramMessage;
use App\Models\OperationalTask;
use App\Notifications\TaskReminderNotification;
use App\Services\WebPushService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

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

    // A retry is Push-only: the visible reminder is not written again.
    Notification::assertNothingSent();

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

    // The second reminder is Push-only: no additional inbox row.
    Notification::assertNothingSent();

    // Ten more minutes: the second reminder is not sent again.
    $this->travelTo(Carbon::parse('2026-10-24 09:20:00', 'UTC'));

    expect($dispatcher->dispatchDue())->toBe(0);

    Notification::assertNothingSent();
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

it('keeps one in-app reminder and creates no push stages when push is disabled', function (): void {
    // R1-05: an already-sent reminder must not be reopened as `initial` just
    // because the person turned Push off. Otherwise every run re-notifies and
    // resets `reminder_attempts`.
    fakePush(0);
    Queue::fake();

    $this->operator->forceFill([
        'notification_preferences' => [
            'in_app' => true,
            'push' => false,
            'telegram_task_reminders' => false,
            'telegram_report_ready' => false,
        ],
    ])->save();

    $task = dueTask($this->operator->id);

    $dispatcher = app(ReminderDispatcher::class);

    // T+0: the initial reminder is the only one that happens.
    expect($dispatcher->dispatchDue())->toBe(1);

    $task->refresh();
    $firstSentAt = $task->reminder_sent_at;

    expect($firstSentAt)->not->toBeNull();

    // T+5, T+10, T+15 and T+60: the candidate query still matches the row
    // (there is no acknowledgement), but `claim()` must return null.
    foreach (['09:05', '09:10', '09:15', '10:00'] as $moment) {
        $this->travelTo(Carbon::parse("2026-10-24 {$moment}:00", 'UTC'));

        expect($dispatcher->dispatchDue())->toBe(0);
    }

    $task->refresh();

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);
    Queue::assertNotPushed(SendTelegramMessage::class);

    expect($task->reminder_attempts)->toBe(0)
        ->and($task->reminder_second_sent_at)->toBeNull()
        ->and($task->reminder_received_at)->toBeNull()
        ->and($task->reminder_dismissed_at)->toBeNull()
        ->and($task->reminder_delivery_stage)->toBeNull()
        ->and($task->reminder_delivery_token)->toBeNull()
        ->and($task->reminder_sent_at->equalTo($firstSentAt))->toBeTrue();
});

it('stops new push stages after the person disables push following the first notice', function (): void {
    // One Push for the initial reminder; no further Push after the toggle.
    fakePush(1);

    $task = dueTask($this->operator->id);

    $dispatcher = app(ReminderDispatcher::class);

    expect($dispatcher->dispatchDue())->toBe(1);

    $task->refresh();
    $firstSentAt = $task->reminder_sent_at;

    $this->operator->forceFill([
        'notification_preferences' => [
            'in_app' => true,
            'push' => false,
            'telegram_task_reminders' => false,
            'telegram_report_ready' => false,
        ],
    ])->save();

    $this->travelTo(Carbon::parse('2026-10-24 09:05:00', 'UTC'));

    expect($dispatcher->dispatchDue())->toBe(0);

    $task->refresh();

    expect($task->reminder_attempts)->toBe(0)
        ->and($task->reminder_second_sent_at)->toBeNull()
        ->and($task->reminder_received_at)->toBeNull()
        ->and($task->reminder_sent_at->equalTo($firstSentAt))->toBeTrue();

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);
});

it('keeps the original retry and second cycle when push stays enabled', function (): void {
    // Initial + technical retry + second reminder, each with its own Push.
    fakePush(3);

    $task = dueTask($this->operator->id);

    $dispatcher = app(ReminderDispatcher::class);

    expect($dispatcher->dispatchDue())->toBe(1);

    // The initial stage is the only one that creates the inbox row.
    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);

    // Five minutes without acknowledgement: one technical retry.
    $this->travelTo(Carbon::parse('2026-10-24 09:05:00', 'UTC'));

    expect($dispatcher->dispatchDue())->toBe(1);

    $task->refresh();

    expect($task->reminder_attempts)->toBe(1);

    // Push-only retry: the accumulated inbox count stays at one.
    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);

    // The device acknowledges the retry; ten minutes later the unique second
    // reminder is allowed.
    $task->forceFill(['reminder_received_at' => now()])->save();

    $this->travelTo(Carbon::parse('2026-10-24 09:15:00', 'UTC'));

    expect($dispatcher->dispatchDue())->toBe(1);

    $task->refresh();

    expect($task->reminder_second_sent_at)->not->toBeNull()
        ->and($task->reminder_attempts)->toBe(1);

    // Push-only second reminder: still exactly one inbox row overall.
    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);

    // Nothing else follows.
    $this->travelTo(Carbon::parse('2026-10-24 10:00:00', 'UTC'));

    expect($dispatcher->dispatchDue())->toBe(0);

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);
});

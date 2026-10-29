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
 * PERF-01-R1 — a reminder reservation must not lose the second notice.
 *
 * The previous implementation wrote `reminder_second_sent_at` during the claim,
 * before the Push call. A worker that stopped in between left the row looking
 * delivered: the second reminder was never sent and never retried. These tests
 * state the two outcomes explicitly, because they are different:
 *
 *  - nothing was delivered  → the reservation expires and the stage is claimed
 *                             again by the next run;
 *  - the in-app row exists  → the visible reminder is durable, the external
 *                             outcome is unknown, and the stage is settled so
 *                             the second notice stays unique.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    $this->operator = userWithPermissions(['tasks.view', 'tasks.manage']);

    Notification::fake();

    $this->travelTo(Carbon::parse('2026-10-24 09:00:00', 'UTC'));
});

function recoveryTask(int $assigneeId, array $overrides = []): OperationalTask
{
    return OperationalTask::factory()->create([
        'assigned_to' => $assigneeId,
        'status' => TaskStatus::Pending,
        'reminder_at' => now()->subMinutes(40),
        'reminder_sent_at' => now()->subMinutes(30),
        'reminder_received_at' => now()->subMinutes(20),
        'reminder_dismissed_at' => null,
        'reminder_second_sent_at' => null,
        'reminder_attempts' => 0,
        ...$overrides,
    ]);
}

it('resends the second reminder when the worker died before delivering anything', function (): void {
    test()->mock(WebPushService::class, function ($mock): void {
        $mock->shouldReceive('sendNotification')->once();
    });

    $task = recoveryTask($this->operator->id, [
        // What a worker leaves behind when it dies between the claim commit and
        // the first local write: a reservation with no internal notification.
        'reminder_delivery_stage' => 'second',
        'reminder_delivery_token' => 'dead-worker',
        'reminder_delivery_started_at' => now()->subMinutes(30),
        'reminder_delivery_internal_at' => null,
    ]);

    expect(app(ReminderDispatcher::class)->dispatchDue())->toBe(1);

    $task->refresh();

    expect($task->reminder_second_sent_at)->not->toBeNull()
        ->and($task->reminder_delivery_token)->toBeNull()
        ->and($task->reminder_delivery_stage)->toBeNull();

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);
});

it('settles the stage without a second notice when the internal reminder was already written', function (): void {
    test()->mock(WebPushService::class, function ($mock): void {
        $mock->shouldNotReceive('sendNotification');
    });

    $writtenAt = now()->subMinutes(30);

    $task = recoveryTask($this->operator->id, [
        'reminder_delivery_stage' => 'second',
        'reminder_delivery_token' => 'dead-worker',
        'reminder_delivery_started_at' => $writtenAt,
        'reminder_delivery_internal_at' => $writtenAt,
    ]);

    expect(app(ReminderDispatcher::class)->dispatchDue())->toBe(0);

    $task->refresh();

    // The second notice is unique: the marker takes the moment the visible
    // reminder was written, not "never happened".
    expect($task->reminder_second_sent_at?->equalTo($writtenAt))->toBeTrue()
        ->and($task->reminder_delivery_token)->toBeNull();

    Notification::assertNothingSent();
});

it('does not duplicate the internal notification when the push fails after it was written', function (): void {
    test()->mock(WebPushService::class, function ($mock): void {
        $mock->shouldReceive('sendNotification')->once()->andThrow(new RuntimeException('push endpoint unreachable'));
    });

    $task = recoveryTask($this->operator->id);

    $dispatcher = app(ReminderDispatcher::class);

    // The internal reminder is written; the push throws. The run reports no
    // successful delivery, but the user-visible part must not be repeated.
    expect($dispatcher->dispatchDue())->toBe(0);

    $task->refresh();

    expect($task->reminder_second_sent_at)->toBeNull()
        ->and($task->reminder_delivery_internal_at)->not->toBeNull()
        ->and($task->reminder_delivery_token)->not->toBeNull();

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);

    // The lease expires while the second reminder is still due. The next run
    // settles the stage instead of sending the visible notice again.
    $this->travelTo(Carbon::parse('2026-10-24 09:06:00', 'UTC'));

    expect($dispatcher->dispatchDue())->toBe(0);

    $task->refresh();

    expect($task->reminder_second_sent_at)->not->toBeNull()
        ->and($task->reminder_delivery_token)->toBeNull();

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);

    // And later runs do not resurrect it.
    $this->travelTo(Carbon::parse('2026-10-24 10:00:00', 'UTC'));

    expect($dispatcher->dispatchDue())->toBe(0);

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);
});

it('keeps the five minute retry cadence after an abandoned initial delivery', function (): void {
    test()->mock(WebPushService::class, function ($mock): void {
        $mock->shouldReceive('sendNotification')->andThrow(new RuntimeException('push endpoint unreachable'));
    });

    $task = recoveryTask($this->operator->id, [
        'reminder_sent_at' => null,
        'reminder_received_at' => null,
        'reminder_at' => now()->subMinute(),
    ]);

    $dispatcher = app(ReminderDispatcher::class);

    expect($dispatcher->dispatchDue())->toBe(0);

    $task->refresh();

    expect($task->reminder_sent_at)->toBeNull()
        ->and($task->reminder_delivery_internal_at)->not->toBeNull();

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);

    // After the lease, the abandoned stage is settled from the moment the
    // visible reminder was written; it is not sent a second time.
    $this->travelTo(Carbon::parse('2026-10-24 09:06:00', 'UTC'));

    expect($dispatcher->dispatchDue())->toBe(0);

    $task->refresh();

    expect($task->reminder_sent_at)->not->toBeNull()
        ->and($task->reminder_attempts)->toBe(0);

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);

    // Five minutes after the settlement a technical retry is due, exactly as
    // before this change.
    $this->travelTo(Carbon::parse('2026-10-24 09:11:00', 'UTC'));

    expect($dispatcher->dispatchDue())->toBe(0);

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 2);
});

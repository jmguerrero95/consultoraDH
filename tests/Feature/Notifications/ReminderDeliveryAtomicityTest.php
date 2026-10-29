<?php

declare(strict_types=1);

use App\Domain\Operations\ReminderDeliveryLost;
use App\Domain\Operations\ReminderDispatcher;
use App\Domain\Operations\TaskStatus;
use App\Models\OperationalTask;
use App\Services\WebPushService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PERF-01-R1 (concurrency) — the internal marker belongs to exactly one claim.
 *
 * `markInternal()` used to update by `reminder_delivery_token` without checking
 * the affected rows. A worker that paused past its lease could therefore create
 * the in-app notification after another worker had taken the stage, write no
 * marker, continue to Push, and duplicate the delivery. The fix requires exactly
 * one updated row and treats zero as a lost reservation: the surrounding
 * transaction rolls the notification back and the Push never runs.
 *
 * These tests deliberately do not use `Notification::fake()`: the point is the
 * real `notifications` table and the transaction around it.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    $this->operator = userWithPermissions(['tasks.view', 'tasks.manage']);

    $this->travelTo(Carbon::parse('2026-10-24 09:00:00', 'UTC'));
});

function atomicTask(int $assigneeId, array $overrides = []): OperationalTask
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

it('commits the notification and the delivery marker in one transaction', function (): void {
    test()->mock(WebPushService::class, function ($mock): void {
        $mock->shouldReceive('sendNotification')->once();
    });

    $task = atomicTask($this->operator->id);

    expect(app(ReminderDispatcher::class)->dispatchDue())->toBe(1);

    $notification = DB::table('notifications')
        ->where('notifiable_id', $this->operator->id)
        ->first();

    expect($notification)->not->toBeNull();

    $data = json_decode((string) $notification->data, true);

    expect($data['type'])->toBe('task_reminder')
        ->and($data['task_id'])->toBe($task->id)
        ->and(DB::table('notifications')->where('notifiable_id', $this->operator->id)->count())->toBe(1);

    $task->refresh();

    // The marker and the notification committed together: the stage is settled
    // and the reservation is released.
    expect($task->reminder_second_sent_at)->not->toBeNull()
        ->and($task->reminder_delivery_token)->toBeNull();
});

it('rolls back the notification and skips Push when the reservation was taken over', function (): void {
    test()->mock(WebPushService::class, function ($mock): void {
        $mock->shouldNotReceive('sendNotification');
    });

    $task = atomicTask($this->operator->id);

    // Simulate the exact race: while this worker is writing the internal
    // notification, another worker takes the stage. The token changes before
    // markInternal() runs.
    $stolen = false;

    DB::listen(function (QueryExecuted $query) use (&$stolen, $task): void {
        if ($stolen || ! str_contains($query->sql, 'insert into "notifications"')) {
            return;
        }

        $stolen = true;

        DB::table('operational_tasks')
            ->where('id', $task->id)
            ->update(['reminder_delivery_token' => 'other-worker']);
    });

    expect(app(ReminderDispatcher::class)->dispatchDue())->toBe(0);

    // The scenario really ran, and neither half of the delivery survived.
    expect($stolen)->toBeTrue()
        ->and(DB::table('notifications')->where('notifiable_id', $this->operator->id)->count())->toBe(0);

    $task->refresh();

    expect($task->reminder_second_sent_at)->toBeNull();
});

it('does not touch a reservation owned by another worker', function (): void {
    $task = atomicTask($this->operator->id, [
        'reminder_delivery_stage' => 'second',
        'reminder_delivery_token' => 'other-worker',
        'reminder_delivery_started_at' => now(),
        'reminder_delivery_internal_at' => null,
    ]);

    $method = new ReflectionMethod(ReminderDispatcher::class, 'markInternal');
    $method->setAccessible(true);

    expect(fn () => $method->invoke(app(ReminderDispatcher::class), 'stale-token', now()))
        ->toThrow(ReminderDeliveryLost::class);

    $task->refresh();

    // The other worker's reservation is untouched, and the stale worker wrote
    // no marker of its own.
    expect($task->reminder_delivery_token)->toBe('other-worker')
        ->and($task->reminder_delivery_internal_at)->toBeNull()
        ->and($task->reminder_second_sent_at)->toBeNull();
});

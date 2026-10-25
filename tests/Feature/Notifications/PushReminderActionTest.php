<?php

declare(strict_types=1);

use App\Domain\Operations\ReminderDispatcher;
use App\Domain\Operations\TaskStatus;
use App\Models\OperationalTask;
use App\Services\WebPushService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * "Descartar" is a write, not a way to hide the tray entry.
 *
 * The service worker posts the signed URL that travelled inside the push
 * payload. The signature names one task and one employee; a valid signature for
 * somebody else's task is still refused. Reception is written only here, by the
 * device, never inferred from the push service accepting the message.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
    Notification::fake();

    $this->operator = userWithPermissions(['tasks.view', 'tasks.manage']);
    $this->other = userWithPermissions(['tasks.view']);

    $this->task = OperationalTask::factory()->create([
        'assigned_to' => $this->operator->id,
        'status' => TaskStatus::Pending,
        'reminder_at' => now()->subMinute(),
        'reminder_sent_at' => now()->subMinutes(6),
        'reminder_received_at' => null,
        'reminder_dismissed_at' => null,
    ]);
});

it('records reception only from the signed device acknowledgement, idempotently', function (): void {
    $url = URL::temporarySignedRoute('api.push.reminders.ack', now()->addDay(), [
        'task' => $this->task->id,
        'user' => $this->operator->id,
    ]);

    $this->postJson($url)->assertOk()->assertJsonPath('acknowledged', true);

    $receivedAt = $this->task->fresh()->reminder_received_at;

    expect($receivedAt)->not->toBeNull();

    $this->travelTo(Carbon::parse('2026-10-24 10:00:00', 'UTC'));

    $this->postJson($url)->assertOk();

    // A second acknowledgement does not move the moment the person saw it.
    expect($this->task->fresh()->reminder_received_at->equalTo($receivedAt))->toBeTrue();
});

it('records a dismissal and the scheduler then stops sending', function (): void {
    Notification::fake();

    test()->mock(WebPushService::class, function ($mock): void {
        $mock->shouldReceive('sendNotification')->never();
    });

    $url = URL::temporarySignedRoute('api.push.reminders.dismiss', now()->addDay(), [
        'task' => $this->task->id,
        'user' => $this->operator->id,
    ]);

    $this->postJson($url)->assertOk()->assertJsonPath('dismissed', true);

    expect($this->task->fresh()->reminder_dismissed_at)->not->toBeNull();

    // Even with the reminder overdue and never acknowledged, a dismissed task
    // produces nothing: the second reminder depended on this value.
    expect(app(ReminderDispatcher::class)->dispatchDue())->toBe(0);

    Notification::assertNothingSent();

    $this->postJson($url)->assertOk();

    expect($this->task->fresh()->reminder_dismissed_at)->not->toBeNull();
});

it('refuses an unsigned action', function (): void {
    $this->postJson('/api/push/reminders/'.$this->task->id.'/ack')
        ->assertForbidden();

    expect($this->task->fresh()->reminder_received_at)->toBeNull();
});

it('refuses a signature that belongs to another employee', function (): void {
    $url = URL::temporarySignedRoute('api.push.reminders.dismiss', now()->addDay(), [
        'task' => $this->task->id,
        'user' => $this->other->id,
    ]);

    $this->postJson($url)->assertForbidden();

    expect($this->task->fresh()->reminder_dismissed_at)->toBeNull();
});

it('refuses an expired signature', function (): void {
    $url = URL::temporarySignedRoute('api.push.reminders.ack', now()->subMinute(), [
        'task' => $this->task->id,
        'user' => $this->operator->id,
    ]);

    $this->postJson($url)->assertForbidden();

    expect($this->task->fresh()->reminder_received_at)->toBeNull();
});

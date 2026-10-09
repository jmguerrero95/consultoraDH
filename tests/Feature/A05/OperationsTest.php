<?php

declare(strict_types=1);

use App\Domain\Operations\Actions\CompleteTask;
use App\Domain\Operations\Actions\ResolveNovelty;
use App\Domain\Operations\CalendarService;
use App\Domain\Operations\NoveltyStatus;
use App\Domain\Operations\ReminderDispatcher;
use App\Domain\Operations\TaskStatus;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientNovelty;
use App\Models\OperationalTask;
use App\Notifications\TaskReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    seedPortfolioRoles();

    $this->client = Client::factory()->create();
    $this->operator = userWithPermissions(['novelties.view', 'novelties.manage', 'tasks.view', 'tasks.manage']);
});

it('creates and resolves a novelty with audit', function (): void {
    $novelty = ClientNovelty::factory()->create([
        'client_id' => $this->client->id,
        'status' => NoveltyStatus::Open,
    ]);

    $result = app(ResolveNovelty::class)->handle($novelty, $this->operator);

    expect($result->status)->toBe(NoveltyStatus::Resolved)
        ->and($result->resolved_at)->not->toBeNull();

    expect(AuditEvent::query()
        ->where('action', 'novelty.resolved')
        ->where('subject_id', $novelty->id)
        ->exists())->toBeTrue();
});

it('creates and completes a task', function (): void {
    $task = OperationalTask::factory()->create([
        'assigned_to' => $this->operator->id,
        'status' => TaskStatus::Pending,
    ]);

    $result = app(CompleteTask::class)->handle($task, $this->operator);

    expect($result->status)->toBe(TaskStatus::Done)
        ->and($result->completed_at)->not->toBeNull();
});

it('dispatches reminders idempotently', function (): void {
    Notification::fake();

    $task = OperationalTask::factory()->create([
        'assigned_to' => $this->operator->id,
        'status' => TaskStatus::Pending,
        'reminder_at' => now()->subHour(),
        'reminder_sent_at' => null,
    ]);

    $dispatcher = app(ReminderDispatcher::class);

    $first = $dispatcher->dispatchDue();
    expect($first)->toBe(1);

    Notification::assertSentTo($this->operator, TaskReminderNotification::class);

    $second = $dispatcher->dispatchDue();
    expect($second)->toBe(0);

    Notification::assertSentToTimes($this->operator, TaskReminderNotification::class, 1);
});

it('bounds the calendar range to 3 months', function (): void {
    $service = app(CalendarService::class);

    $start = CarbonImmutable::parse('2025-01-01');
    $end = CarbonImmutable::parse('2025-06-01');

    expect(fn () => $service->events($start, $end))
        ->toThrow(InvalidArgumentException::class);
});

it('returns calendar events within a valid range', function (): void {
    OperationalTask::factory()->create([
        'assigned_to' => $this->operator->id,
        'due_on' => '2025-03-15',
        'status' => TaskStatus::Pending,
    ]);

    $service = app(CalendarService::class);
    $events = $service->events(
        CarbonImmutable::parse('2025-03-01'),
        CarbonImmutable::parse('2025-03-31'),
    );

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('task_due');
});

it('enforces permissions on novelty and task mutations', function (): void {
    $viewer = userWithPermissions(['novelties.view', 'tasks.view']);

    $novelty = ClientNovelty::factory()->create([
        'client_id' => $this->client->id,
        'status' => NoveltyStatus::Open,
    ]);

    $this->actingAs($viewer)->postJson('/api/novelties', [
        'client_id' => $this->client->id,
        'category' => 'general',
        'title' => 'Test',
    ])->assertForbidden();

    $this->actingAs($viewer)->postJson('/api/tasks', [
        'title' => 'Test task',
        'assigned_to' => $this->operator->id,
        'priority' => 'normal',
    ])->assertForbidden();
});

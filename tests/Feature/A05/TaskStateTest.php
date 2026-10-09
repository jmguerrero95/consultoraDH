<?php

declare(strict_types=1);

use App\Domain\Operations\Actions\CancelTask;
use App\Domain\Operations\Actions\CompleteTask;
use App\Domain\Operations\Actions\ReassignTask;
use App\Domain\Operations\OperationNotApplicable;
use App\Domain\Operations\TaskStatus;
use App\Domain\Users\UserStatus;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\OperationalTask;
use App\Models\User;

/**
 * A05-R1 §4 — a task's state and its assignee.
 *
 * The gap: the three task mutations decided from whatever the router had bound, so two
 * people acting at once could both read `pending` and the second write could erase the
 * first. And any `users.id` was accepted as an assignee, which meant an internal task —
 * with its reminder, its calendar entry and its operations board — could be assigned to a
 * portal client account that can open none of them.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    $this->client = Client::factory()->create();
    $this->operator = userWithPermissions(['tasks.view', 'tasks.manage']);
    $this->colleague = userWithPermissions(['tasks.view']);

    $this->portal = User::factory()->create([
        'account_type' => 'client',
        'client_id' => $this->client->id,
        'status' => UserStatus::Active,
    ]);
});

/** A pending task held by the operator. */
function pendingTask($test): OperationalTask
{
    return OperationalTask::factory()->create([
        'client_id' => $test->client->id,
        'assigned_to' => $test->operator->id,
        'created_by' => $test->operator->id,
        'status' => TaskStatus::Pending,
    ]);
}

// ---------------------------------------------------------------------------
// TEST T1 — the stale instance
// ---------------------------------------------------------------------------

it('T1: a stale copy cannot cancel or reassign a task that was meanwhile completed', function (): void {
    $task = pendingTask($this);

    $copyA = OperationalTask::query()->findOrFail($task->id);
    $copyB = OperationalTask::query()->findOrFail($task->id);

    expect($copyA->status)->toBe(TaskStatus::Pending)
        ->and($copyB->status)->toBe(TaskStatus::Pending);

    app(CompleteTask::class)->handle($copyA, $this->operator);

    // Copy B still believes the task is open. Before the lock these were accepted and
    // the completion was silently discarded.
    expect(fn () => app(CancelTask::class)->handle($copyB, $this->operator))
        ->toThrow(OperationNotApplicable::class);

    expect(fn () => app(ReassignTask::class)->handle($copyB, $this->colleague, $this->operator))
        ->toThrow(OperationNotApplicable::class);

    $final = OperationalTask::query()->findOrFail($task->id);

    expect($final->status)->toBe(TaskStatus::Done)
        ->and($final->cancelled_at)->toBeNull()
        ->and((int) $final->assigned_to)->toBe((int) $this->operator->id);

    // Exactly one completion is on record: the contradictory action never happened.
    expect(AuditEvent::query()
        ->where('action', 'task.completed')
        ->where('subject_id', $task->id)
        ->count())->toBe(1);

    expect(AuditEvent::query()
        ->where('action', 'task.cancelled')
        ->where('subject_id', $task->id)
        ->count())->toBe(0);
});

it('T1: a task cannot be completed twice from two copies', function (): void {
    $task = pendingTask($this);

    $copyA = OperationalTask::query()->findOrFail($task->id);
    $copyB = OperationalTask::query()->findOrFail($task->id);

    app(CompleteTask::class)->handle($copyA, $this->operator);

    expect(fn () => app(CompleteTask::class)->handle($copyB, $this->operator))
        ->toThrow(OperationNotApplicable::class);

    expect(AuditEvent::query()
        ->where('action', 'task.completed')
        ->where('subject_id', $task->id)
        ->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// TEST T2 — the assignee must be an active colleague
// ---------------------------------------------------------------------------

it('T2: refuses to create a task assigned to a portal client account', function (): void {
    $this->actingAs($this->operator)
        ->postJson('/api/tasks', [
            'client_id' => $this->client->id,
            'title' => 'Llamar al cliente',
            'assigned_to' => $this->portal->id,
            'priority' => 'normal',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'assignee_not_staff');

    expect(OperationalTask::query()->count())->toBe(0);
});

it('T2: refuses to reassign a task to a portal client account', function (): void {
    $task = pendingTask($this);

    $this->actingAs($this->operator)
        ->postJson("/api/tasks/{$task->id}/reassign", ['assigned_to' => $this->portal->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'assignee_not_staff');

    expect(OperationalTask::query()->findOrFail($task->id)->assigned_to)
        ->toBe($this->operator->id);
});

it('T2: refuses to assign a task to a suspended colleague', function (): void {
    $suspended = User::factory()->create([
        'account_type' => 'staff',
        'status' => UserStatus::Inactive,
    ]);

    $this->actingAs($this->operator)
        ->postJson('/api/tasks', [
            'client_id' => $this->client->id,
            'title' => 'Tarea para alguien suspendido',
            'assigned_to' => $suspended->id,
            'priority' => 'normal',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'assignee_not_active');

    expect(OperationalTask::query()->count())->toBe(0);
});

it('T2: an active colleague can still be assigned', function (): void {
    $this->actingAs($this->operator)
        ->postJson('/api/tasks', [
            'client_id' => $this->client->id,
            'title' => 'Tarea legítima',
            'assigned_to' => $this->colleague->id,
            'priority' => 'high',
        ])
        ->assertCreated()
        ->assertJsonPath('assigned_to', $this->colleague->id);

    $task = OperationalTask::query()->firstOrFail();

    // And reassigning to another colleague works too.
    $this->actingAs($this->operator)
        ->postJson("/api/tasks/{$task->id}/reassign", ['assigned_to' => $this->operator->id])
        ->assertOk()
        ->assertJsonPath('assigned_to', $this->operator->id);
});

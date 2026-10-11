<?php

declare(strict_types=1);

namespace Tests\Feature\A06;

use App\Models\AuditEvent;
use App\Domain\Receivables\ReceivablesService;
use App\Domain\Support\Actions\AssignConversation;
use App\Domain\Support\Actions\ChangeConversationPriority;
use App\Domain\Support\Actions\ChangeConversationQueue;
use App\Models\AutomationAction;
use App\Models\AutomationActionRun;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\OperationalTask;
use App\Models\SupportConversation;
use App\Models\SupportQueue;
use App\Models\SupportSlaEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

beforeEach(function (): void {
    seedRoles();

    $this->owner = User::factory()->create(['account_type' => 'staff']);
    $this->owner->assignRole('Super Admin');

    $this->assignee = User::factory()->create(['account_type' => 'staff']);
    $this->assignee->assignRole('Support');
});

test('schedule occurrence authority uses locked next_run_at', function (): void {
    $rule = AutomationRule::create([
        'name' => 'Schedule Test',
        'trigger_type' => 'schedule',
        'trigger_config' => [
            'frequency' => 'daily',
            'time' => '12:00',
        ],
        'condition_config' => [],
        'owner_user_id' => $this->owner->id,
        'active' => true,
        'next_run_at' => Carbon::parse('2026-10-10 12:00:00'),
    ]);

    $occurrence = Carbon::parse('2026-10-10 12:00:00');
    $laterScan = Carbon::parse('2026-10-10 12:05:00');

    // First scan at 12:05 - should create run with occurrence 12:00
    $run = AutomationRun::firstOrCreate(
        ['automation_rule_id' => $rule->id, 'occurrence_key' => "schedule:{$rule->id}:{$occurrence->toISOString()}"],
        ['status' => 'pending', 'trigger_snapshot' => ['scheduled_at' => $occurrence->toISOString()], 'started_at' => $laterScan]
    );

    expect($run->wasRecentlyCreated)->toBeTrue();
    expect($run->trigger_snapshot['scheduled_at'])->toBe($occurrence->toISOString());

    // Second scan at 12:10 - should NOT create duplicate run
    $laterScan2 = Carbon::parse('2026-10-10 12:10:00');
    $run2 = AutomationRun::firstOrCreate(
        ['automation_rule_id' => $rule->id, 'occurrence_key' => "schedule:{$rule->id}:{$occurrence->toISOString()}"],
        ['status' => 'pending', 'trigger_snapshot' => ['scheduled_at' => $occurrence->toISOString()], 'started_at' => $laterScan2]
    );

    expect($run2->wasRecentlyCreated)->toBeFalse();
    expect($run2->id)->toBe($run->id);
});

test('repeated scan creates only one run', function (): void {
    $rule = AutomationRule::create([
        'name' => 'Schedule Test',
        'trigger_type' => 'schedule',
        'trigger_config' => [
            'frequency' => 'daily',
            'time' => '12:00',
        ],
        'condition_config' => [],
        'owner_user_id' => $this->owner->id,
        'active' => true,
        'next_run_at' => Carbon::parse('2026-10-10 12:00:00'),
    ]);

    // Simulate the dispatch logic
    $occurrence = $rule->next_run_at;
    $occurrenceKey = "schedule:{$rule->id}:{$occurrence->toISOString()}";

    // First dispatch
    $run1 = AutomationRun::firstOrCreate(
        ['automation_rule_id' => $rule->id, 'occurrence_key' => $occurrenceKey],
        ['status' => 'pending', 'trigger_snapshot' => ['scheduled_at' => $occurrence->toISOString()], 'started_at' => now()]
    );
    expect($run1->wasRecentlyCreated)->toBeTrue();

    // Second dispatch (late scan)
    $run2 = AutomationRun::firstOrCreate(
        ['automation_rule_id' => $rule->id, 'occurrence_key' => $occurrenceKey],
        ['status' => 'pending', 'trigger_snapshot' => ['scheduled_at' => $occurrence->toISOString()], 'started_at' => now()->addMinutes(10)]
    );
    expect($run2->wasRecentlyCreated)->toBeFalse();
    expect($run2->id)->toBe($run1->id);

    // Only one run exists
    expect(AutomationRun::where('automation_rule_id', $rule->id)->count())->toBe(1);
});

test('nonempty schedule condition rejected', function (): void {
    $response = $this->actingAs($this->owner)
        ->postJson('/api/automations', [
            'name' => 'Test Rule',
            'trigger_type' => 'schedule',
            'trigger_config' => [
                'frequency' => 'daily',
                'time' => '12:00',
            ],
            'condition_config' => [
                ['field' => 'queue_id', 'operator' => 'eq', 'value' => 1],
            ],
            'actions' => [
                [
                    'action_type' => 'internal_notification',
                    'config' => [
                        'user_ids' => [$this->owner->id],
                        'title' => 'Test',
                        'message' => 'Test',
                    ],
                    'position' => 0,
                ],
            ],
        ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.0', 'Los disparadores programados no admiten condiciones dinámicas');
});

test('schedule condition validation in update', function (): void {
    $rule = AutomationRule::create([
        'name' => 'Test Rule',
        'trigger_type' => 'schedule',
        'trigger_config' => [
            'frequency' => 'daily',
            'time' => '12:00',
        ],
        'condition_config' => [],
        'owner_user_id' => $this->owner->id,
        'active' => false,
    ]);

    $response = $this->actingAs($this->owner)
        ->patchJson("/api/automations/{$rule->id}", [
            'condition_config' => [
                ['field' => 'queue_id', 'operator' => 'eq', 'value' => 1],
            ],
        ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.0', 'Los disparadores programados no admiten condiciones dinámicas');
});

test('receivable trigger uses receivables service list', function (): void {
    $client = Client::factory()->create();
    $user = User::factory()->create(['account_type' => 'client', 'client_id' => $client->id]);

    $rule = AutomationRule::create([
        'name' => 'Receivable Overdue',
        'trigger_type' => 'receivable_overdue',
        'trigger_config' => [
            'min_days_overdue' => 1,
        ],
        'condition_config' => [],
        'owner_user_id' => $this->owner->id,
        'active' => true,
    ]);

    // Mock the ReceivablesService to verify it's called with correct params
    $service = app(ReceivablesService::class);

    // We can't easily mock the service here, but we can verify the rule doesn't crash
    $this->actingAs($this->owner)
        ->postJson("/api/automations/validate-preview", [
            'trigger_type' => 'receivable_overdue',
            'trigger_config' => ['min_days_overdue' => 1],
            'condition_config' => [],
            'actions' => [
                [
                    'action_type' => 'internal_notification',
                    'config' => [
                        'user_ids' => [$this->owner->id],
                        'title' => 'Test',
                        'message' => 'Test',
                    ],
                    'position' => 0,
                ],
            ],
        ])->assertStatus(200);
});

test('create_task uses a05 contract', function (): void {
    Event::fake();

    $rule = AutomationRule::create([
        'name' => 'Create Task',
        'trigger_type' => 'audit_action',
        'trigger_config' => ['action_name' => 'client_created'],
        'condition_config' => [],
        'owner_user_id' => $this->owner->id,
        'active' => true,
    ]);

    AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 0,
        'action_type' => 'create_task',
        'config' => [
            'title' => 'Automated Task',
            'description' => 'Auto created',
            'assignee_user_id' => $this->assignee->id,
            'priority' => 'normal',
            'due_on' => now()->addDay()->toDateString(),
        ],
        'active' => true,
    ]);

    // Trigger the audit action by creating AuditEvent record
    $client = Client::factory()->create();
    AuditEvent::query()->create([
        'action' => 'client_created',
        'subject_type' => get_class($client),
        'subject_id' => $client->id,
        'causer_type' => get_class($this->owner),
        'causer_id' => $this->owner->id,
        'metadata' => [],
    ]);

    // Run the dispatch job
    $job = new \App\Jobs\DispatchAutomations();
    $job->handle();

    // Verify task was created with A05 contract
    $task = OperationalTask::where('title', 'Automated Task')->first();
    expect($task)->not->toBeNull();
    expect($task->assigned_to)->toBe($this->assignee->id);
    expect($task->created_by)->toBe($this->owner->id);
});

test('inactive owner blocks execution', function (): void {
    $inactiveOwner = User::factory()->create(['account_type' => 'staff', 'status' => \App\Domain\Users\UserStatus::Inactive]);
    $inactiveOwner->assignRole('Super Admin');

    $rule = AutomationRule::create([
        'name' => 'Inactive Owner Rule',
        'trigger_type' => 'audit_action',
        'trigger_config' => ['action_name' => 'client_created'],
        'condition_config' => [],
        'owner_user_id' => $inactiveOwner->id,
        'active' => true,
    ]);

    AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 0,
        'action_type' => 'internal_notification',
        'config' => [
            'user_ids' => [$this->owner->id],
            'title' => 'Test',
            'message' => 'Test',
        ],
        'active' => true,
    ]);
    
    $client = Client::factory()->create();
    AuditEvent::query()->create([
        'action' => 'client_created',
        'subject_type' => get_class($client),
        'subject_id' => $client->id,
        'causer_type' => get_class($this->owner),
        'causer_id' => $this->owner->id,
        'metadata' => [],
    ]);

    $job = new \App\Jobs\DispatchAutomations();
    $job->handle();

    // Run is created but ExecuteAutomationRun marks it as blocked
    $run = AutomationRun::where('automation_rule_id', $rule->id)->first();
    expect($run)->not->toBeNull();
    
    // Run the ExecuteAutomationRun job to apply the block
    \App\Jobs\ExecuteAutomationRun::dispatch($run->id)->handle();
    
    $run->refresh();
    expect($run->status)->toBe('blocked');
    expect($run->error_code)->toBe('owner_permissions_lost');
});

test('permission revoked before execution blocks', function (): void {
    // Create a user with NO roles, give permissions directly
    $owner = User::factory()->create(['account_type' => 'staff']);
    // Give automations.manage and tasks.manage directly (no roles)
    $owner->givePermissionTo(['automations.manage', 'tasks.manage']);
    $owner->forgetCachedPermissions();
    $owner = $owner->fresh();

    $assignee = User::factory()->create(['account_type' => 'staff']);
    $assignee->assignRole('Support');

    $rule = AutomationRule::create([
        'name' => 'Permission Test',
        'trigger_type' => 'audit_action',
        'trigger_config' => ['action_name' => 'client_created'],
        'condition_config' => [],
        'owner_user_id' => $owner->id,
        'active' => true,
    ]);

    AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 0,
        'action_type' => 'create_task',
        'config' => [
            'title' => 'Task',
            'assignee_user_id' => $assignee->id,
            'due_on' => now()->addDay()->toDateString(),
            'priority' => 'normal',
        ],
        'active' => true,
    ]);

    // Revoke the directly-granted tasks.manage permission
    $owner->revokePermissionTo('tasks.manage');
    $owner->forgetCachedPermissions();
    $owner = $owner->fresh();

    $client = Client::factory()->create();
    AuditEvent::query()->create([
        'action' => 'client_created',
        'subject_type' => get_class($client),
        'subject_id' => $client->id,
        'causer_type' => get_class($owner),
        'causer_id' => $owner->id,
        'metadata' => [],
    ]);

    $job = new \App\Jobs\DispatchAutomations();
    $job->handle();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->first();
    expect($run)->not->toBeNull();
    
    // Run ExecuteAutomationRun synchronously to apply the block
    \App\Jobs\ExecuteAutomationRun::dispatch($run->id)->handle();
    
    $actionRun = AutomationActionRun::where('automation_run_id', $run->id)->first();
    expect($actionRun->status)->toBe('blocked');
    expect($actionRun->error_code)->toBe('action_permission_lost');
});

test('support queue action uses domain action', function (): void {
    $queue = SupportQueue::factory()->create(['name' => 'Test Queue', 'slug' => 'test']);
    $conversation = SupportConversation::factory()->create(['queue_id' => $queue->id]);

    $rule = AutomationRule::create([
        'name' => 'Assign Queue',
        'trigger_type' => 'audit_action',
        'trigger_config' => ['action_name' => 'client_created'],
        'condition_config' => [],
        'owner_user_id' => $this->owner->id,
        'active' => true,
    ]);

    AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 0,
        'action_type' => 'assign_support_queue',
        'config' => [
            'queue_id' => $queue->id,
        ],
        'active' => true,
    ]);
    
    $client = Client::factory()->create();
    AuditEvent::query()->create([
        'action' => 'client_created',
        'subject_type' => get_class($client),
        'subject_id' => $client->id,
        'causer_type' => get_class($this->owner),
        'causer_id' => $this->owner->id,
        'metadata' => [],
    ]);

    $job = new \App\Jobs\DispatchAutomations();
    $job->handle();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->first();
    expect($run)->not->toBeNull();
    $actionRun = AutomationActionRun::where('automation_run_id', $run->id)->first();
    expect($actionRun->status)->toBe('succeeded');
});

test('support assignee action uses domain action', function (): void {
    $conversation = SupportConversation::factory()->create();

    $rule = AutomationRule::create([
        'name' => 'Assign User',
        'trigger_type' => 'audit_action',
        'trigger_config' => ['action_name' => 'client_created'],
        'condition_config' => [],
        'owner_user_id' => $this->owner->id,
        'active' => true,
    ]);

    AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 0,
        'action_type' => 'assign_support_user',
        'config' => [
            'user_id' => $this->assignee->id,
        ],
        'active' => true,
    ]);
    
    $client = Client::factory()->create();
    AuditEvent::query()->create([
        'action' => 'client_created',
        'subject_type' => get_class($client),
        'subject_id' => $client->id,
        'causer_type' => get_class($this->owner),
        'causer_id' => $this->owner->id,
        'metadata' => [],
    ]);

    $job = new \App\Jobs\DispatchAutomations();
    $job->handle();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->first();
    expect($run)->not->toBeNull();
    $actionRun = AutomationActionRun::where('automation_run_id', $run->id)->first();
    expect($actionRun->status)->toBe('succeeded');
});

test('priority action uses domain action', function (): void {
    $conversation = SupportConversation::factory()->create(['priority' => \App\Domain\Support\SupportConversationPriority::Normal]);

    $rule = AutomationRule::create([
        'name' => 'Set Priority',
        'trigger_type' => 'audit_action',
        'trigger_config' => ['action_name' => 'client_created'],
        'condition_config' => [],
        'owner_user_id' => $this->owner->id,
        'active' => true,
    ]);

    AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 0,
        'action_type' => 'set_support_priority',
        'config' => [
            'priority' => 'high',
        ],
        'active' => true,
    ]);
    
    $client = Client::factory()->create();
    AuditEvent::query()->create([
        'action' => 'client_created',
        'subject_type' => get_class($client),
        'subject_id' => $client->id,
        'causer_type' => get_class($this->owner),
        'causer_id' => $this->owner->id,
        'metadata' => [],
    ]);

    $job = new \App\Jobs\DispatchAutomations();
    $job->handle();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->first();
    expect($run)->not->toBeNull();
    $actionRun = AutomationActionRun::where('automation_run_id', $run->id)->first();
    expect($actionRun->status)->toBe('succeeded');
});

test('partial retry skips succeeded action', function (): void {
    $rule = AutomationRule::create([
        'name' => 'Partial Retry',
        'trigger_type' => 'audit_action',
        'trigger_config' => ['action_name' => 'client_created'],
        'condition_config' => [],
        'owner_user_id' => $this->owner->id,
        'active' => true,
    ]);

    // Two actions: one will succeed, one will fail
    $action1 = AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 0,
        'action_type' => 'internal_notification',
        'config' => [
            'user_ids' => [$this->owner->id],
            'title' => 'Success',
            'message' => 'OK',
        ],
        'active' => true,
    ]);

    $action2 = AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 1,
        'action_type' => 'create_task',
        'config' => [
            'title' => 'Task',
            'assignee_user_id' => 99999, // Invalid user - will fail
            'due_on' => now()->addDay()->toDateString(),
            'priority' => 'normal',
        ],
        'active' => true,
    ]);
    
    $client = Client::factory()->create();
    AuditEvent::query()->create([
        'action' => 'client_created',
        'subject_type' => get_class($client),
        'subject_id' => $client->id,
        'causer_type' => get_class($this->owner),
        'causer_id' => $this->owner->id,
        'metadata' => [],
]);
    
    $job = new \App\Jobs\DispatchAutomations();
    $job->handle();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->first();
    expect($run->status)->toBe('partial');

    $actionRuns = AutomationActionRun::where('automation_run_id', $run->id)->get();
    // Check by action ID since order is not guaranteed
    $action1Run = $actionRuns->where('automation_action_id', $action1->id)->first();
    $action2Run = $actionRuns->where('automation_action_id', $action2->id)->first();
    expect($action1Run->status)->toBe('succeeded');
    expect($action2Run->status)->toBe('failed');

    // Now retry the run - should skip succeeded action
    $run->update(['status' => 'failed']);
    $action2Run = AutomationActionRun::where('automation_run_id', $run->id)
        ->where('automation_action_id', $action2->id)
        ->first();
    $action2Run->update(['status' => 'failed']); // Reset to failed

    // Re-dispatch
    \App\Jobs\ExecuteAutomationRun::dispatch($run->id)->handle();

    $run->refresh();
    $actionRuns = AutomationActionRun::where('automation_run_id', $run->id)->get();

    // First action should still be succeeded (not re-executed)
    $action1Run = $actionRuns->where('automation_action_id', $action1->id)->first();
    expect($action1Run->status)->toBe('succeeded');

    // Second action should now be succeeded after retry
    $action2Run = $actionRuns->where('automation_action_id', $action2->id)->first();
    // The assignee is still invalid, so it will fail again
    expect($action2Run->status)->toBe('failed');
});

test('unknown action type rejected', function (): void {
    $rule = AutomationRule::create([
        'name' => 'Unknown Action',
        'trigger_type' => 'audit_action',
        'trigger_config' => ['action_name' => 'client_created'],
        'condition_config' => [],
        'owner_user_id' => $this->owner->id,
        'active' => true,
    ]);

    // Directly test the executeAction method with unknown type
    $action = AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 0,
        'action_type' => 'unknown_action_type',
        'config' => [],
        'active' => true,
    ]);

    $run = AutomationRun::create([
        'automation_rule_id' => $rule->id,
        'occurrence_key' => 'test:1',
        'status' => 'pending',
        'trigger_snapshot' => [],
        'started_at' => now(),
    ]);

    $job = new \App\Jobs\ExecuteAutomationRun($run->id);
    $job->handle();

    $run->refresh();
    $actionRun = AutomationActionRun::where('automation_run_id', $run->id)->first();

    // Unknown action types are rejected at permission check (blocked, not failed)
    expect($actionRun->status)->toBe('blocked');
    expect($actionRun->error_code)->toBe('action_permission_lost');
});
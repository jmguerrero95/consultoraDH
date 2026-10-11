<?php

declare(strict_types=1);

/**
 * A06 — the automation dispatcher and the executor it hands work to.
 *
 * ## What these tests are actually asserting
 *
 * The earlier version of this file passed while the feature was inert. Every
 * support action reported `succeeded` having changed nothing, because the
 * conversation it was supposed to act on was never reachable from the trigger
 * snapshot; the schedule tests called `firstOrCreate()` from the test body, so
 * they were asserting the test's own copy of the logic rather than the job's; and
 * the partial-retry test retried with the cause still unfixed and expected it to
 * fail again, which a permanently broken action would also satisfy.
 *
 * So the rule applied throughout this file is: **drive the real jobs and assert
 * the resulting business state**, never the bookkeeping that describes it. An
 * action that ran is proven by the queue, the assignee or the priority having
 * moved; a retry that worked is proven by the run reaching `succeeded`.
 */
use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Domain\Support\SupportConversationPriority;
use App\Domain\Users\UserStatus;
use App\Jobs\DispatchAutomations;
use App\Jobs\ExecuteAutomationRun;
use App\Models\AuditEvent;
use App\Models\AutomationAction;
use App\Models\AutomationActionRun;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use App\Models\OperationalTask;
use App\Models\SupportConversation;
use App\Models\SupportQueue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses()->group('A06');
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedRoles();

    $this->owner = User::factory()->create(['account_type' => 'staff']);
    $this->owner->assignRole('Super Admin');

    $this->assignee = User::factory()->create(['account_type' => 'staff']);
    $this->assignee->assignRole('Support');
});

/**
 * A rule with one action, wired to a trigger of the given type.
 *
 * @param  array<string, mixed>  $triggerConfig
 * @param  array<string, mixed>  $actionConfig
 */
function automationRule(string $triggerType, array $triggerConfig, string $actionType, array $actionConfig, User $owner, int $position = 0): AutomationRule
{
    $rule = AutomationRule::create([
        'name' => 'Rule '.$triggerType.' '.$actionType,
        'trigger_type' => $triggerType,
        'trigger_config' => $triggerConfig,
        'condition_config' => [],
        'owner_user_id' => $owner->id,
        'active' => true,
    ]);

    AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => $position,
        'action_type' => $actionType,
        'config' => $actionConfig,
        'active' => true,
    ]);

    return $rule;
}

/**
 * An audit event whose subject is the conversation itself.
 *
 * This is the shape a "something happened on this thread" event has, and it is
 * what lets the support actions find the conversation they are supposed to act
 * on. Pointing the event at some unrelated record instead is what let those
 * actions pass their assertions while doing nothing.
 */
function conversationAuditEvent(SupportConversation $conversation, string $action = 'support_message_received'): AuditEvent
{
    return AuditEvent::query()->create([
        'action' => $action,
        'subject_type' => SupportConversation::class,
        'subject_id' => $conversation->id,
        'causer_type' => User::class,
        'causer_id' => null,
        'metadata' => ['conversation_id' => $conversation->id],
    ]);
}

/**
 * Run the dispatcher the way the scheduler does.
 */
function dispatchAutomations(): void
{
    (new DispatchAutomations)->handle();
}

/* -------------------------------------------------------------------------- */
/* | | R4 — the schedule occurrence is the locked one                              | */
/* -------------------------------------------------------------------------- */

test('a due schedule produces one run for the occurrence and advances the rule', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:05:00'));

    $rule = automationRule(
        'schedule',
        ['frequency' => 'daily', 'time' => '12:00'],
        'internal_notification',
        ['user_ids' => [$this->owner->id], 'title' => 'T', 'message' => 'M'],
        $this->owner,
    );

    // The rule was due at 12:00 and nobody ran it until 12:05.
    $rule->update(['next_run_at' => Carbon::parse('2026-10-10 12:00:00')]);

    dispatchAutomations();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    expect($run->occurrence_key)->toBe("schedule:{$rule->id}:".Carbon::parse('2026-10-10 12:00:00')->toISOString());

    // The snapshot records the occurrence, not the moment the scan happened.
    expect($run->trigger_snapshot['scheduled_at'])->toBe(Carbon::parse('2026-10-10 12:00:00')->toISOString());

    // The rule moved forward to the next 12:00, so tomorrow is a new occurrence.
    expect($rule->fresh()->next_run_at->toDateTimeString())->toBe('2026-10-11 12:00:00');

    Carbon::setTestNow();
});

test('rescanning the same occurrence does not produce a second run', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:05:00'));

    $rule = automationRule(
        'schedule',
        ['frequency' => 'daily', 'time' => '12:00'],
        'internal_notification',
        ['user_ids' => [$this->owner->id], 'title' => 'T', 'message' => 'M'],
        $this->owner,
    );

    $rule->update(['next_run_at' => Carbon::parse('2026-10-10 12:00:00')]);

    dispatchAutomations();
    dispatchAutomations();
    dispatchAutomations();

    // Three scans of one occurrence, one run.
    expect(AutomationRun::where('automation_rule_id', $rule->id)->count())->toBe(1);

    // The rule kept advancing with each scan but never re-fired the occurrence.
    expect($rule->fresh()->next_run_at->toDateTimeString())->toBe('2026-10-11 12:00:00');

    Carbon::setTestNow();
});

test('a rule that is not due is left alone', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-10 11:00:00'));

    $rule = automationRule(
        'schedule',
        ['frequency' => 'daily', 'time' => '12:00'],
        'internal_notification',
        ['user_ids' => [$this->owner->id], 'title' => 'T', 'message' => 'M'],
        $this->owner,
    );

    $rule->update(['next_run_at' => Carbon::parse('2026-10-10 12:00:00')]);

    dispatchAutomations();

    expect(AutomationRun::where('automation_rule_id', $rule->id)->count())->toBe(0);
    expect($rule->fresh()->next_run_at->toDateTimeString())->toBe('2026-10-10 12:00:00');

    Carbon::setTestNow();
});

test('a deactivated rule produces no run even when it is due', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:05:00'));

    $rule = automationRule(
        'schedule',
        ['frequency' => 'daily', 'time' => '12:00'],
        'internal_notification',
        ['user_ids' => [$this->owner->id], 'title' => 'T', 'message' => 'M'],
        $this->owner,
    );

    $rule->update([
        'next_run_at' => Carbon::parse('2026-10-10 12:00:00'),
        'active' => false,
    ]);

    dispatchAutomations();

    expect(AutomationRun::where('automation_rule_id', $rule->id)->count())->toBe(0);

    Carbon::setTestNow();
});

test('a schedule rule carrying dynamic conditions is skipped, not executed', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:05:00'));

    $rule = automationRule(
        'schedule',
        ['frequency' => 'daily', 'time' => '12:00'],
        'internal_notification',
        ['user_ids' => [$this->owner->id], 'title' => 'T', 'message' => 'M'],
        $this->owner,
    );

    // Force an invalid configuration past the API, which would normally refuse it.
    $rule->update([
        'next_run_at' => Carbon::parse('2026-10-10 12:00:00'),
        'condition_config' => [['field' => 'queue_id', 'operator' => 'eq', 'value' => 1]],
    ]);

    Notification::fake();

    dispatchAutomations();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    expect($run->status)->toBe('skipped');
    Notification::assertNothingSent();

    Carbon::setTestNow();
});

/* -------------------------------------------------------------------------- */
/* | | R4 — receivables: every page, not just the first                           | */
/* -------------------------------------------------------------------------- */

/**
 * One client with an obligation due before the reference date and left unpaid.
 */
function overdueClient(string $name): Client
{
    $employer = a03_employer(client: Client::factory()->create(['first_names' => $name]));

    $period = MonthlyPeriod::query()->firstOrCreate(
        ['period_month' => MonthValue::fromKey('2026-08')->startsOn()],
        ['status' => 'open', 'opened_at' => now()],
    );

    MonthlyObligation::factory()->forPeriod($period)->create([
        'client_id' => $employer['client']->id,
        'company_id' => $employer['company']->id,
        'base_amount_cop' => 200000,
        // Due 2026-09-10, so it is overdue against any reference date from now.
        'due_on' => MonthValue::fromKey('2026-08')->startsOn()->copy()->addMonthNoOverflow()->day(10),
    ]);

    return $employer['client'];
}

test('the receivable trigger walks every page of overdue clients', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-10 09:00:00'));

    // A batch size of one turns three clients into three pages, so the loop has
    // to advance twice to see all of them.
    $rule = automationRule(
        'receivable_overdue',
        ['min_days_overdue' => 1, 'per_page' => 1],
        'internal_notification',
        ['user_ids' => [$this->owner->id], 'title' => 'Vencido', 'message' => 'Revisar'],
        $this->owner,
    );

    overdueClient('Ana');
    overdueClient('Beto');
    overdueClient('Caro');

    Notification::fake();

    dispatchAutomations();

    $runs = AutomationRun::where('automation_rule_id', $rule->id)->get();

    // Three clients, three runs. Reading only the first page would have produced
    // one and dropped the other two without any error.
    expect($runs)->toHaveCount(3);

    expect($runs->pluck('occurrence_key')->filter(fn ($k) => str_starts_with($k, 'receivable_overdue:')))->toHaveCount(3);

    // The snapshot carries the client the run is about, so the action can address them.
    $clientIds = $runs->map(fn ($r) => $r->trigger_snapshot['client_id'])->unique();
    expect($clientIds)->toHaveCount(3);

    Carbon::setTestNow();
});

test('the receivable trigger is idempotent across repeated scans', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-10 09:00:00'));

    $rule = automationRule(
        'receivable_overdue',
        ['min_days_overdue' => 1, 'per_page' => 1],
        'internal_notification',
        ['user_ids' => [$this->owner->id], 'title' => 'Vencido', 'message' => 'Revisar'],
        $this->owner,
    );

    overdueClient('Ana');
    overdueClient('Beto');

    dispatchAutomations();
    dispatchAutomations();

    expect(AutomationRun::where('automation_rule_id', $rule->id)->count())->toBe(2);

    Carbon::setTestNow();
});

/* -------------------------------------------------------------------------- */
/* | | R4 — the actions move real business state                                   | */
/* -------------------------------------------------------------------------- */

test('the queue action moves the conversation to the configured queue', function (): void {
    Notification::fake();

    $queue = SupportQueue::factory()->create(['name' => 'Facturación', 'slug' => 'facturacion']);
    $origin = SupportQueue::factory()->create(['name' => 'General', 'slug' => 'general']);
    $conversation = SupportConversation::factory()->create([
        'queue_id' => $origin->id,
        'status' => 'waiting_staff',
    ]);

    expect($conversation->queue_id)->toBe($origin->id);

    $rule = automationRule(
        'audit_action',
        ['action_name' => 'support_message_received'],
        'assign_support_queue',
        ['queue_id' => $queue->id],
        $this->owner,
    );

    conversationAuditEvent($conversation);

    dispatchAutomations();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    (new ExecuteAutomationRun($run->id))->handle();

    expect($run->fresh()->status)->toBe('succeeded');

    // The business state, not the action row.
    expect($conversation->fresh()->queue_id)->toBe($queue->id);

    $actionRun = AutomationActionRun::where('automation_run_id', $run->id)->sole();
    expect($actionRun->status)->toBe('succeeded');
});

test('the assignee action moves the conversation to the configured user', function (): void {
    Notification::fake();

    $conversation = SupportConversation::factory()->create(['status' => 'waiting_staff']);
    expect($conversation->assigned_to_user_id)->toBeNull();

    $rule = automationRule(
        'audit_action',
        ['action_name' => 'support_message_received'],
        'assign_support_user',
        ['user_id' => $this->assignee->id],
        $this->owner,
    );

    conversationAuditEvent($conversation);

    dispatchAutomations();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    (new ExecuteAutomationRun($run->id))->handle();

    expect($run->fresh()->status)->toBe('succeeded');
    expect($conversation->fresh()->assigned_to_user_id)->toBe($this->assignee->id);
});

test('the priority action changes the priority of the conversation', function (): void {
    Notification::fake();

    $conversation = SupportConversation::factory()->create([
        'priority' => SupportConversationPriority::Normal,
        'status' => 'waiting_staff',
    ]);

    expect($conversation->priority)->toBe(SupportConversationPriority::Normal);

    $rule = automationRule(
        'audit_action',
        ['action_name' => 'support_message_received'],
        'set_support_priority',
        ['priority' => 'high'],
        $this->owner,
    );

    conversationAuditEvent($conversation);

    dispatchAutomations();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    (new ExecuteAutomationRun($run->id))->handle();

    expect($run->fresh()->status)->toBe('succeeded');
    expect($conversation->fresh()->priority)->toBe(SupportConversationPriority::High);
});

test('the task action creates a real task owned by the rule owner', function (): void {
    Notification::fake();

    $rule = automationRule(
        'audit_action',
        ['action_name' => 'client_created'],
        'create_task',
        [
            'title' => 'Revisar expediente',
            'description' => 'Creado por la automatización',
            'assignee_user_id' => $this->assignee->id,
            'priority' => 'normal',
            'due_on' => now()->addDay()->toDateString(),
        ],
        $this->owner,
    );

    $client = Client::factory()->create();
    AuditEvent::query()->create([
        'action' => 'client_created',
        'subject_type' => Client::class,
        'subject_id' => $client->id,
        'causer_type' => User::class,
        'causer_id' => $this->owner->id,
        'metadata' => ['client_id' => $client->id],
    ]);

    dispatchAutomations();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    (new ExecuteAutomationRun($run->id))->handle();

    expect($run->fresh()->status)->toBe('succeeded');

    $task = OperationalTask::where('title', 'Revisar expediente')->sole();
    expect($task->assigned_to)->toBe($this->assignee->id);
    expect($task->created_by)->toBe($this->owner->id);
});

/* -------------------------------------------------------------------------- */
/* | | R4 — a retry after the cause is fixed actually completes                    | */
/* -------------------------------------------------------------------------- */

test('a partial run completes on retry once the cause is corrected', function (): void {
    Notification::fake();

    $rule = AutomationRule::create([
        'name' => 'Partial Retry',
        'trigger_type' => 'audit_action',
        'trigger_config' => ['action_name' => 'client_created'],
        'condition_config' => [],
        'owner_user_id' => $this->owner->id,
        'active' => true,
    ]);

    // Position 0 succeeds, position 1 cannot: the assignee does not exist yet.
    $succeeding = AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 0,
        'action_type' => 'internal_notification',
        'config' => ['user_ids' => [$this->owner->id], 'title' => 'Aviso', 'message' => 'Corrijo algo'],
        'active' => true,
    ]);

    $failing = AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 1,
        'action_type' => 'create_task',
        'config' => [
            'title' => 'Tareacorrecta',
            'assignee_user_id' => 999999,
            'due_on' => now()->addDay()->toDateString(),
            'priority' => 'normal',
        ],
        'active' => true,
    ]);

    $client = Client::factory()->create();
    AuditEvent::query()->create([
        'action' => 'client_created',
        'subject_type' => Client::class,
        'subject_id' => $client->id,
        'causer_type' => User::class,
        'causer_id' => $this->owner->id,
        'metadata' => ['client_id' => $client->id],
    ]);

    dispatchAutomations();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    (new ExecuteAutomationRun($run->id))->handle();

    // First pass: one action landed, the other failed, so the run is partial.
    expect($run->fresh()->status)->toBe('partial');

    $notificationRun = AutomationActionRun::where('automation_run_id', $run->id)
        ->where('automation_action_id', $succeeding->id)
        ->sole();
    expect($notificationRun->status)->toBe('succeeded');

    $failedRun = AutomationActionRun::where('automation_run_id', $run->id)
        ->where('automation_action_id', $failing->id)
        ->sole();
    expect($failedRun->status)->toBe('failed');

    expect(OperationalTask::where('title', 'Tarea correcta')->exists())->toBeFalse();

    // Fix the cause: point the action at a real, active staff member.
    $failing->update(['config' => [
        'title' => 'Tarea correcta',
        'assignee_user_id' => $this->assignee->id,
        'due_on' => now()->addDay()->toDateString(),
        'priority' => 'normal',
    ]]);
    $failing->refresh();

    // Retry the partial run as the scheduler would.
    (new ExecuteAutomationRun($run->id))->handle();

    // Now everything succeeded, so the run is no longer partial.
    expect($run->fresh()->status)->toBe('succeeded');

    // The previously failed action produced the task it was asked for.
    $failedRun->refresh();
    expect($failedRun->status)->toBe('succeeded');

    expect(OperationalTask::where('title', 'Tarea correcta')->sole()->assigned_to)->toBe($this->assignee->id);

    // And the action that had already succeeded was not run a second time: it
    // keeps its original finished_at, because a retry skips it outright.
    $firstFinishedAt = $notificationRun->fresh()->finished_at;
    (new ExecuteAutomationRun($run->id))->handle();
    expect($notificationRun->fresh()->finished_at->toDateTimeString())->toBe($firstFinishedAt->toDateTimeString());
})->group('R4');

/* -------------------------------------------------------------------------- */
/* | | R4 — authority: who may run this, and what an unknown action does          | */
/* -------------------------------------------------------------------------- */

test('an inactive owner blocks the run', function (): void {
    Notification::fake();

    $inactiveOwner = User::factory()->create(['account_type' => 'staff', 'status' => UserStatus::Inactive]);
    $inactiveOwner->assignRole('Super Admin');

    $rule = automationRule(
        'audit_action',
        ['action_name' => 'client_created'],
        'internal_notification',
        ['user_ids' => [$this->owner->id], 'title' => 'T', 'message' => 'M'],
        $inactiveOwner,
    );

    $client = Client::factory()->create();
    AuditEvent::query()->create([
        'action' => 'client_created',
        'subject_type' => Client::class,
        'subject_id' => $client->id,
        'causer_type' => User::class,
        'causer_id' => $inactiveOwner->id,
        'metadata' => [],
    ]);

    dispatchAutomations();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    (new ExecuteAutomationRun($run->id))->handle();

    expect($run->fresh()->status)->toBe('blocked');
    expect($run->fresh()->error_code)->toBe('owner_permissions_lost');

    // Nothing reached anybody.
    Notification::assertNothingSent();
});

test('a permission revoked after the rule was created blocks the action', function (): void {
    Notification::fake();

    $owner = User::factory()->create(['account_type' => 'staff']);
    $owner->givePermissionTo(['automations.manage', 'tasks.manage']);

    $rule = automationRule(
        'audit_action',
        ['action_name' => 'client_created'],
        'create_task',
        [
            'title' => 'Tarea',
            'assignee_user_id' => $this->assignee->id,
            'due_on' => now()->addDay()->toDateString(),
            'priority' => 'normal',
        ],
        $owner,
    );

    // Take away exactly the permission this action needs.
    $owner->revokePermissionTo('tasks.manage');

    $client = Client::factory()->create();
    AuditEvent::query()->create([
        'action' => 'client_created',
        'subject_type' => Client::class,
        'subject_id' => $client->id,
        'causer_type' => User::class,
        'causer_id' => $owner->id,
        'metadata' => [],
    ]);

    dispatchAutomations();

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::where('automation_run_id', $run->id)->sole();
    expect($actionRun->status)->toBe('blocked');
    expect($actionRun->error_code)->toBe('action_permission_lost');

    // Blocked means it never ran, so no task exists.
    expect(OperationalTask::where('title', 'Tarea')->exists())->toBeFalse();
});

test('an unknown action type is blocked rather than silently ignored', function (): void {
    Notification::fake();

    $rule = AutomationRule::create([
        'name' => 'Unknown Action',
        'trigger_type' => 'audit_action',
        'trigger_config' => ['action_name' => 'client_created'],
        'condition_config' => [],
        'owner_user_id' => $this->owner->id,
        'active' => true,
    ]);

    AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 0,
        'action_type' => 'unknown_action_type',
        'config' => [],
        'active' => true,
    ]);

    $run = AutomationRun::create([
        'automation_rule_id' => $rule->id,
        'occurrence_key' => 'test:unknown-action',
        'status' => 'pending',
        'trigger_snapshot' => [],
        'started_at' => now(),
    ]);

    (new ExecuteAutomationRun($run->id))->handle();

    // Blocked, and the run is not reported as succeeded.
    expect($run->fresh()->status)->toBe('partial');

    $actionRun = AutomationActionRun::where('automation_run_id', $run->id)->sole();
    expect($actionRun->status)->toBe('blocked');
    expect($actionRun->error_code)->toBe('action_permission_lost');
});

/* -------------------------------------------------------------------------- */
/* | | R4 — configuration that the API refuses                                    | */
/* -------------------------------------------------------------------------- */

test('a schedule rule with dynamic conditions is refused', function (): void {
    $response = $this->actingAs($this->owner)->postJson('/api/automations', [
        'name' => 'Regla inválida',
        'trigger_type' => 'schedule',
        'trigger_config' => ['frequency' => 'daily', 'time' => '12:00'],
        'condition_config' => [['field' => 'queue_id', 'operator' => 'eq', 'value' => 1]],
        'actions' => [[
            'action_type' => 'internal_notification',
            'config' => ['user_ids' => [$this->owner->id], 'title' => 'T', 'message' => 'M'],
            'position' => 0,
        ]],
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.0', 'Los disparadores programados no admiten condiciones dinámicas');

    expect(AutomationRule::where('name', 'Regla inválida')->exists())->toBeFalse();
});

test('adding dynamic conditions to an existing schedule rule is refused', function (): void {
    $rule = automationRule(
        'schedule',
        ['frequency' => 'daily', 'time' => '12:00'],
        'internal_notification',
        ['user_ids' => [$this->owner->id], 'title' => 'T', 'message' => 'M'],
        $this->owner,
    );

    // An active rule refuses edits outright — that is the separate, deliberate
    // guard, and it is checked before the payload is even validated.
    $this->actingAs($this->owner)
        ->patchJson("/api/automations/{$rule->id}", ['name' => 'Otro nombre'])
        ->assertStatus(409);

    // Deactivated, the edit is accepted and the condition is refused.
    $rule->update(['active' => false]);

    $response = $this->actingAs($this->owner)->patchJson("/api/automations/{$rule->id}", [
        'condition_config' => [['field' => 'queue_id', 'operator' => 'eq', 'value' => 1]],
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('errors.0', 'Los disparadores programados no admiten condiciones dinámicas');

    // The stored rule is untouched.
    expect($rule->fresh()->condition_config)->toBe([]);
});

/* -------------------------------------------------------------------------- */
/* | | R5 — an action with nothing to act on must fail, not succeed               | */
/* -------------------------------------------------------------------------- */

/**
 * A rule whose trigger carries no conversation, driven end to end.
 *
 * `client_created` is the realistic case: the event is about a client, so the
 * snapshot has no `conversation_id` for a support action to address. The rule is
 * therefore perfectly creatable from the vocabulary, and it can only ever fail.
 */
function ruleWithoutConversationContext(
    string $actionType,
    array $actionConfig,
    User $owner,
    array $metadata = [],
): AutomationRule {
    $rule = automationRule(
        'audit_action',
        ['action_name' => 'client_created'],
        $actionType,
        $actionConfig,
        $owner,
    );

    $client = Client::factory()->create();

    AuditEvent::query()->create([
        'action' => 'client_created',
        'subject_type' => Client::class,
        'subject_id' => $client->id,
        'causer_type' => User::class,
        'causer_id' => $owner->id,
        'metadata' => $metadata,
    ]);

    dispatchAutomations();

    return $rule;
}

test('the queue action fails when the trigger names no conversation', function (): void {
    Notification::fake();

    $target = SupportQueue::factory()->create(['name' => 'Destino', 'slug' => 'destino']);
    $origin = SupportQueue::factory()->create(['name' => 'Origen', 'slug' => 'origen']);

    // A conversation that exists and must be left exactly as it is.
    $untouched = SupportConversation::factory()->create([
        'queue_id' => $origin->id,
        'status' => 'waiting_staff',
    ]);

    $rule = ruleWithoutConversationContext('assign_support_queue', ['queue_id' => $target->id], $this->owner);

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    (new ExecuteAutomationRun($run->id))->handle();

    // Failed, not quietly done.
    $actionRun = AutomationActionRun::where('automation_run_id', $run->id)->sole();
    expect($actionRun->status)->toBe('failed');
    expect($run->fresh()->status)->toBe('partial');

    // And nothing moved.
    expect($untouched->fresh()->queue_id)->toBe($origin->id);
    expect(SupportConversation::where('queue_id', $target->id)->count())->toBe(0);
});

test('the assignee action fails when the trigger names a conversation that does not exist', function (): void {
    Notification::fake();

    // The id is present and plausible, so this is the case that slips through a
    // null check: the snapshot is not empty, it points at nothing.
    $rule = ruleWithoutConversationContext(
        'assign_support_user',
        ['user_id' => $this->assignee->id],
        $this->owner,
        ['conversation_id' => 987654],
    );

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    expect($run->trigger_snapshot['conversation_id'])->toBe(987654);

    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::where('automation_run_id', $run->id)->sole();
    expect($actionRun->status)->toBe('failed');
    expect($run->fresh()->status)->toBe('partial');

    // Nobody was assigned anything.
    expect(SupportConversation::whereNotNull('assigned_to_user_id')->count())->toBe(0);
});

test('the priority action fails when the trigger names no conversation', function (): void {
    Notification::fake();

    $untouched = SupportConversation::factory()->create([
        'priority' => SupportConversationPriority::Normal,
        'status' => 'waiting_staff',
    ]);

    $rule = ruleWithoutConversationContext('set_support_priority', ['priority' => 'high'], $this->owner);

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::where('automation_run_id', $run->id)->sole();
    expect($actionRun->status)->toBe('failed');
    expect($run->fresh()->status)->toBe('partial');

    // No priority was raised anywhere.
    expect($untouched->fresh()->priority)->toBe(SupportConversationPriority::Normal);
    expect(SupportConversation::where('priority', 'high')->count())->toBe(0);
});

test('the email action fails when a context recipient resolves to nobody', function (): void {
    Notification::fake();
    Mail::fake();

    // `assigned_staff` is context-dependent: the recipient is the conversation's
    // assignee. With no conversation there is nothing to name, so previously the
    // empty `foreach` did nothing and the action reported a sent message.
    $rule = ruleWithoutConversationContext(
        'send_email',
        [
            'recipient_type' => 'assigned_staff',
            'subject' => 'Aviso',
            'body' => 'Contenido',
        ],
        $this->owner,
    );

    $run = AutomationRun::where('automation_rule_id', $rule->id)->sole();
    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::where('automation_run_id', $run->id)->sole();
    expect($actionRun->status)->toBe('failed');
    expect($run->fresh()->status)->toBe('partial');

    // No message left the building.
    Mail::assertNothingSent();
});

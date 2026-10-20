<?php

declare(strict_types=1);

use App\Domain\Users\UserStatus;
use App\Jobs\ExecuteAutomationRun;
use App\Jobs\SendAutomationEmail;
use App\Mail\AutomationEmail;
use App\Models\AutomationAction;
use App\Models\AutomationActionRun;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\OperationalTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * T04/T05 — one execution per run and action, and a delivery is only a success
 * once it happened.
 *
 * The race is reproduced the way PostgreSQL sees it: one worker has already
 * written `running` (fresh timestamp), and a second job for the same run
 * arrives. The second must do nothing. The same applies at action level.
 */
uses()->group('A06');
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedRoles();

    $this->owner = User::factory()->create(['account_type' => 'staff']);
    $this->owner->assignRole('Super Admin');

    $this->assignee = User::factory()->create([
        'account_type' => 'staff',
        'status' => 'active',
    ]);
    $this->assignee->assignRole('Support');
});

/**
 * A rule with one action, and a pending run for it.
 */
function concurrencyRule(string $actionType, array $config, User $owner): AutomationRun
{
    $rule = AutomationRule::create([
        'name' => 'Concurrency '.$actionType,
        'trigger_type' => 'audit_action',
        'trigger_config' => ['action_name' => 'client_created'],
        'condition_config' => [],
        'owner_user_id' => $owner->id,
        'active' => true,
    ]);

    AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 0,
        'action_type' => $actionType,
        'config' => $config,
        'active' => true,
    ]);

    return AutomationRun::create([
        'automation_rule_id' => $rule->id,
        'occurrence_key' => 'client_created:1',
        'status' => 'pending',
        'trigger_snapshot' => ['client_id' => 1],
        'started_at' => now(),
    ]);
}

function taskActionConfig(User $assignee): array
{
    return [
        'title' => 'Tarea de automatización',
        'description' => 'Creada por la regla',
        'assignee_user_id' => $assignee->id,
        'priority' => 'normal',
        'due_on' => now()->addDay()->toDateString(),
    ];
}

test('a run already claimed by another worker is not executed again', function (): void {
    $run = concurrencyRule('create_task', taskActionConfig($this->assignee), $this->owner);
    $run->update(['status' => 'running', 'started_at' => now()]);

    (new ExecuteAutomationRun($run->id))->handle();

    expect(OperationalTask::query()->count())->toBe(0)
        ->and($run->fresh()->status)->toBe('running');
});

test('a run left running by a dead worker can be reclaimed once it is stale', function (): void {
    $run = concurrencyRule('create_task', taskActionConfig($this->assignee), $this->owner);
    $run->update(['status' => 'running', 'started_at' => now()->subMinutes(10)]);

    (new ExecuteAutomationRun($run->id))->handle();

    expect(OperationalTask::query()->count())->toBe(1)
        ->and($run->fresh()->status)->toBe('succeeded');
});

test('an action in flight is not executed by a second worker', function (): void {
    $run = concurrencyRule('create_task', taskActionConfig($this->assignee), $this->owner);

    AutomationActionRun::create([
        'automation_run_id' => $run->id,
        'automation_action_id' => $run->rule->actions()->first()->id,
        'status' => 'running',
        'started_at' => now(),
    ]);

    (new ExecuteAutomationRun($run->id))->handle();

    expect(OperationalTask::query()->count())->toBe(0);

    $actionRun = AutomationActionRun::query()->where('automation_run_id', $run->id)->sole();
    expect($actionRun->status)->toBe('running');
});

test('a stale action claim is retried and creates the task once', function (): void {
    $run = concurrencyRule('create_task', taskActionConfig($this->assignee), $this->owner);

    AutomationActionRun::create([
        'automation_run_id' => $run->id,
        'automation_action_id' => $run->rule->actions()->first()->id,
        'status' => 'running',
        'started_at' => now()->subMinutes(10),
    ]);

    (new ExecuteAutomationRun($run->id))->handle();

    expect(OperationalTask::query()->count())->toBe(1);
});

test('an email action is queued, not succeeded, until the delivery job runs', function (): void {
    Queue::fake();
    Mail::fake();

    $run = concurrencyRule('send_email', [
        'recipient_type' => 'specific_user',
        'specific_user_id' => $this->assignee->id,
        'subject' => 'Aviso',
        'body' => 'Contenido',
    ], $this->owner);

    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::query()->where('automation_run_id', $run->id)->sole();

    expect($actionRun->status)->toBe('queued')
        ->and($actionRun->finished_at)->toBeNull()
        ->and($run->fresh()->status)->toBe('queued');

    Queue::assertPushed(SendAutomationEmail::class);

    /** @var SendAutomationEmail $job */
    $job = Queue::pushed(SendAutomationEmail::class)->first();
    $job->handle();

    expect($actionRun->fresh()->status)->toBe('succeeded')
        ->and($run->fresh()->status)->toBe('succeeded');

    Mail::assertSent(AutomationEmail::class, function ($mail): bool {
        return $mail->hasTo($this->assignee->email);
    });
});

test('an email action with no resolvable recipient fails instead of queueing nothing', function (): void {
    Queue::fake();

    $run = concurrencyRule('send_email', [
        'recipient_type' => 'specific_user',
        'specific_user_id' => 999999,
        'subject' => 'Aviso',
        'body' => 'Contenido',
    ], $this->owner);

    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::query()->where('automation_run_id', $run->id)->sole();

    expect($actionRun->status)->toBe('failed')
        ->and($run->fresh()->status)->toBe('partial');

    Queue::assertNotPushed(SendAutomationEmail::class);
});

test('an inactive assignee blocks the task action and the run is not a success', function (): void {
    $inactive = User::factory()->create([
        'account_type' => 'staff',
        'status' => UserStatus::Inactive,
    ]);

    $run = concurrencyRule('create_task', taskActionConfig($inactive), $this->owner);

    (new ExecuteAutomationRun($run->id))->handle();

    expect(OperationalTask::query()->count())->toBe(0)
        ->and($run->fresh()->status)->toBe('partial');
});

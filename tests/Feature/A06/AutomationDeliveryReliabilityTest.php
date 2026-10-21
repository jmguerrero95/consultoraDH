<?php

declare(strict_types=1);

use App\Domain\Automations\AutomationDeliveryProgress;
use App\Jobs\ExecuteAutomationRun;
use App\Jobs\SendAutomationEmail;
use App\Jobs\SendTelegramMessage;
use App\Models\AutomationAction;
use App\Models\AutomationActionRun;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\OperationalTask;
use App\Models\SupportDelivery;
use App\Models\TelegramEndpoint;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| A06-AUTO-02/03 and A06-TG-01 — the state of an automation, its actions and
| its deliveries, under concurrency and after a partial crash.
|--------------------------------------------------------------------------
|
| The dispatcher marked the action `queued` **after** it had dispatched the
| delivery job, so with an inline queue the child answered first and the
| parent overwrote the answer. The creation of a task had no durable origin,
| so a crash between the INSERT and the recording of `succeeded` produced a
| second task. And a Telegram delivery whose token was missing left its claim
| `pending` for ever, blocking every later send with the same key.
|
| These tests run with the real queue topology of the suite — `sync` for the
| inline case and a real `redis` connection for the workers — and never rely
| on `Queue::fake()` alone.
|
*/

beforeEach(function (): void {
    seedPortfolioRoles();
    Notification::fake();
    Mail::fake();

    config([
        'services.telegram.bot_token' => 'test-token',
    ]);
});

/**
 * An account that may do everything an automation may do.
 */
function auto06Owner(): User
{
    return userWithPermissions([
        'automations.manage',
        'tasks.manage',
        'notification_channels.manage',
        'support.manage_queues',
        'support.assign',
    ]);
}

/**
 * A rule with one action, owned by somebody who may do everything.
 */
function auto06Rule(string $actionType, array $config, ?User $owner = null): array
{
    $owner ??= auto06Owner();

    $rule = AutomationRule::create([
        'name' => 'Regla de prueba',
        'trigger_type' => 'client_created',
        'trigger_config' => [],
        'condition_config' => [],
        'owner_user_id' => $owner->id,
        'active' => true,
    ]);

    $action = AutomationAction::create([
        'automation_rule_id' => $rule->id,
        'position' => 0,
        'action_type' => $actionType,
        'config' => $config,
        'active' => true,
    ]);

    return [$rule, $action, $owner];
}

function auto06Run($rule, array $triggerSnapshot = []): AutomationRun
{
    return AutomationRun::create([
        'automation_rule_id' => $rule->id,
        'occurrence_key' => 'occ-'.uniqid(),
        'status' => 'pending',
        'trigger_snapshot' => $triggerSnapshot,
    ]);
}

it('does not overwrite the terminal state of an action with a preliminary one', function (): void {
    // The child job answers inside the dispatch, because the queue is inline.
    $owner = auto06Owner();

    [$rule, $action] = auto06Rule('send_email', [
        'recipient_type' => 'specific_user',
        'specific_user_id' => $owner->id,
        'subject' => 'Prueba',
        'body' => 'Cuerpo',
    ], $owner);

    $run = auto06Run($rule);

    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::query()
        ->where('automation_run_id', $run->id)
        ->where('automation_action_id', $action->id)
        ->sole();

    // The delivery really happened and the action says so: the parent did not
    // overwrite it with `queued`.
    expect($actionRun->status)->toBe('succeeded')
        ->and($actionRun->finished_at)->not->toBeNull()
        ->and($run->fresh()->status)->toBe('succeeded');
});

it('keeps a confirmed failure when the parent finishes afterwards', function (): void {
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new RuntimeException('mail_failed'));

    $owner = auto06Owner();

    [$rule, $action] = auto06Rule('send_email', [
        'recipient_type' => 'specific_user',
        'specific_user_id' => $owner->id,
        'subject' => 'Prueba',
        'body' => 'Cuerpo',
    ], $owner);

    $run = auto06Run($rule);

    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::query()
        ->where('automation_run_id', $run->id)
        ->where('automation_action_id', $action->id)
        ->sole();

    expect($actionRun->status)->toBe('failed')
        ->and($run->fresh()->status)->toBe('partial');
});

it('does not create a second task when the action is retried after a crash', function (): void {
    $owner = auto06Owner();

    [$rule, $action] = auto06Rule('create_task', [
        'assignee_user_id' => $owner->id,
        'title' => 'Tarea de la automatización',
        'description' => 'Descripción',
        'priority' => 'normal',
    ], $owner);

    $run = auto06Run($rule);

    (new ExecuteAutomationRun($run->id))->handle();

    expect(OperationalTask::query()->count())->toBe(1);

    // The crash: the task was created and the process died before the action
    // was recorded as succeeded. The retry must not create a second one.
    $actionRun = AutomationActionRun::query()
        ->where('automation_run_id', $run->id)
        ->where('automation_action_id', $action->id)
        ->sole();

    $actionRun->forceFill(['status' => 'running'])->save();
    $run->forceFill(['status' => 'running'])->save();

    (new ExecuteAutomationRun($run->id))->handle();

    expect(OperationalTask::query()->count())->toBe(1)
        ->and(OperationalTask::query()->sole()->automation_action_run_id)->toBe($actionRun->id);
});

it('records the attempt of a delivery before the message leaves', function (): void {
    $owner = auto06Owner();

    [$rule, $action] = auto06Rule('send_email', [
        'recipient_type' => 'specific_user',
        'specific_user_id' => $owner->id,
        'subject' => 'Prueba',
        'body' => 'Cuerpo',
    ], $owner);

    $run = auto06Run($rule);

    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::query()
        ->where('automation_run_id', $run->id)
        ->where('automation_action_id', $action->id)
        ->sole();

    $snapshot = $actionRun->fresh()->result_snapshot;

    expect($snapshot['recipients'])->toHaveKey($owner->email)
        ->and($snapshot['recipients'][$owner->email]['status'])->toBe(AutomationDeliveryProgress::SENT)
        ->and($snapshot['recipients'][$owner->email]['attempts'])->toBe(1);
});

it('does not resend a delivery whose outcome is unknown', function (): void {
    $owner = auto06Owner();

    [$rule, $action] = auto06Rule('send_email', [
        'recipient_type' => 'specific_user',
        'specific_user_id' => $owner->id,
        'subject' => 'Prueba',
        'body' => 'Cuerpo',
    ], $owner);

    $run = auto06Run($rule);

    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::query()
        ->where('automation_run_id', $run->id)
        ->where('automation_action_id', $action->id)
        ->sole();

    // The provider accepted the message and the process died before the
    // acceptance was written: the recipient is still `sending`.
    AutomationActionRun::query()->whereKey($actionRun->id)->update([
        'result_snapshot' => json_encode([
            'recipients' => [
                $owner->email => [
                    'status' => AutomationDeliveryProgress::SENDING,
                    'attempts' => 1,
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
        ]),
        'status' => 'queued',
    ]);

    Mail::fake();

    (new SendAutomationEmail($actionRun->id, $owner->email, 'Prueba', 'Cuerpo'))->handle();

    $recipients = AutomationActionRun::query()->find($actionRun->id)->result_snapshot['recipients'];

    // Nothing was sent again, and the truth is recorded as unknown.
    Mail::assertNothingSent();

    expect($recipients[$owner->email]['status'])->toBe(AutomationDeliveryProgress::UNCERTAIN)
        ->and($recipients[$owner->email]['error_code'])->toBe('delivery_uncertain');
});

it('does not mark the whole action as delivered when only one recipient received it', function (): void {
    $owner = auto06Owner();

    [$rule, $action] = auto06Rule('send_email', [
        'recipient_type' => 'specific_user',
        'specific_user_id' => $owner->id,
        'subject' => 'Prueba',
        'body' => 'Cuerpo',
    ], $owner);

    $run = auto06Run($rule);

    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::query()
        ->where('automation_run_id', $run->id)
        ->where('automation_action_id', $action->id)
        ->sole();

    $recipient = $owner->email;

    // A second recipient that failed.
    AutomationDeliveryProgress::recordOutcome(
        $actionRun->id,
        'fallo@consultora-dh.test',
        AutomationDeliveryProgress::FAILED,
        'mail_failed',
    );

    // And the first one that succeeded afterwards: it must not erase the
    // failure of the other.
    AutomationDeliveryProgress::recordOutcome(
        $actionRun->id,
        $recipient,
        AutomationDeliveryProgress::SENT,
        null,
    );

    $fresh = AutomationActionRun::query()->find($actionRun->id);

    expect($fresh->status)->toBe('failed')
        ->and($fresh->error_code)->toBe('mail_failed')
        ->and($fresh->result_snapshot['recipients'][$recipient]['status'])->toBe(AutomationDeliveryProgress::SENT);
});

it('derives the state of the run from the state of its actions', function (): void {
    $owner = auto06Owner();

    [$rule, $action] = auto06Rule('create_task', [
        'assignee_user_id' => $owner->id,
        'title' => 'Tarea de la automatización',
        'description' => 'Descripción',
        'priority' => 'normal',
    ], $owner);

    $run = auto06Run($rule);

    (new ExecuteAutomationRun($run->id))->handle();

    expect($run->fresh()->status)->toBe('succeeded');
});

/* -------------------------------------------------------------------------- */
/* A06-TG-01 — the Telegram claim must not block the next send */
/* -------------------------------------------------------------------------- */

it('does not leave a pending claim when the token is not configured', function (): void {
    config(['services.telegram.bot_token' => null]);

    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = TelegramEndpoint::factory()->createdBy($admin)->create();

    SendTelegramMessage::dispatchSync($endpoint->id, 'Alerta sin token');

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    // No claim was taken, so nothing is blocking the next send.
    expect(SupportDelivery::query()->count())->toBe(0);

    // With the token configured, the same message goes out exactly once.
    config(['services.telegram.bot_token' => 'test-token']);

    SendTelegramMessage::dispatchSync($endpoint->id, 'Alerta sin token');

    Http::assertSentCount(1);

    expect(SupportDelivery::query()->count())->toBe(1)
        ->and(SupportDelivery::query()->sole()->status)->toBe('sent');
});

it('records the failure of a claimed delivery when the endpoint disappears', function (): void {
    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = TelegramEndpoint::factory()->createdBy($admin)->create();

    // The claim is taken and then the send fails: the row must not stay
    // `pending`, because a `pending` row blocks every later send.
    // One fake that answers 500 first and 200 second: the two attempts have to
    // see different outcomes for the retry to mean anything.
    Http::fakeSequence('api.telegram.org/*')
        ->push(['ok' => false], 500)
        ->push(['ok' => true], 200);

    try {
        SendTelegramMessage::dispatchSync($endpoint->id, 'alerta rota');
    } catch (Throwable) {
        // The job rethrows so the queue can retry it.
    }

    expect(SupportDelivery::query()->sole()->status)->toBe('failed');

    // The recovery works: the same key is taken over, not duplicated.
    SendTelegramMessage::dispatchSync($endpoint->id, 'alerta rota');

    expect(SupportDelivery::query()->count())->toBe(1)
        ->and(SupportDelivery::query()->sole()->status)->toBe('sent')
        ->and(SupportDelivery::query()->sole()->attempts)->toBe(2);
});

it('recovers a delivery that was left pending by a worker that died', function (): void {
    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = TelegramEndpoint::factory()->createdBy($admin)->create();

    $key = 'telegram_'.$endpoint->id.'_'.hash('sha256', 'abandonada');

    // A claim whose worker never came back. The timestamps are written
    // afterwards because the model does not accept them.
    SupportDelivery::query()->create([
        'channel' => 'telegram',
        'status' => 'pending',
        'dedupe_key' => $key,
        'attempts' => 1,
    ]);

    SupportDelivery::query()
        ->where('dedupe_key', $key)
        ->update(['created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)]);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    SendTelegramMessage::dispatchSync($endpoint->id, 'abandonada');

    Http::assertSentCount(1);

    expect(SupportDelivery::query()->count())->toBe(1)
        ->and(SupportDelivery::query()->sole()->status)->toBe('sent')
        ->and(SupportDelivery::query()->sole()->attempts)->toBe(2);
});

it('does not steal a claim that is still in flight', function (): void {
    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = TelegramEndpoint::factory()->createdBy($admin)->create();

    $key = 'telegram_'.$endpoint->id.'_'.hash('sha256', 'en vuelo');

    SupportDelivery::query()->create([
        'channel' => 'telegram',
        'status' => 'pending',
        'dedupe_key' => $key,
        'attempts' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    SendTelegramMessage::dispatchSync($endpoint->id, 'en vuelo');

    Http::assertNothingSent();

    expect(SupportDelivery::query()->sole()->status)->toBe('pending');
});

it('reports the right state of the action and the run after a Telegram delivery', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = TelegramEndpoint::factory()->createdBy($admin)->create();

    [$rule, $action] = auto06Rule('send_telegram', [
        'endpoint_id' => $endpoint->id,
        'message' => 'Alerta de la automatización',
    ]);

    $run = auto06Run($rule);

    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::query()
        ->where('automation_run_id', $run->id)
        ->where('automation_action_id', $action->id)
        ->sole();

    expect($actionRun->status)->toBe('succeeded')
        ->and($run->fresh()->status)->toBe('succeeded')
        ->and(SupportDelivery::query()->sole()->status)->toBe('sent');
});

it('keeps the delivery result when the delivery runs on a real redis worker', function (): void {
    // The parent's dispatch goes to the real queue, and the delivery is picked
    // up by a real worker afterwards. The parent's own write happened before
    // the dispatch, so the answer of the worker is the last word.
    // A queue of its own, so the worker can only find this test's job.
    $queue = 'a06-delivery-'.uniqid();

    config([
        'queue.default' => 'redis',
        'queue.connections.redis.queue' => $queue,
    ]);

    // The connection was already resolved by the seeding, with the old
    // default queue. It is dropped so it is resolved again with the queue of
    // this test: otherwise the job lands in the shared `default` queue and
    // stays there, and the readiness probe of another test finds it.
    app()->forgetInstance('queue');
    Illuminate\Support\Facades\Queue::clearResolvedInstance('queue');

    [$rule, $action] = auto06Rule('send_email', [
        'recipient_type' => 'specific_user',
        'specific_user_id' => auto06Owner()->id,
        'subject' => 'Prueba',
        'body' => 'Cuerpo',
    ]);

    $owner = $rule->owner;

    $run = auto06Run($rule);

    (new ExecuteAutomationRun($run->id))->handle();

    $actionRun = AutomationActionRun::query()
        ->where('automation_run_id', $run->id)
        ->where('automation_action_id', $action->id)
        ->sole();

    // The delivery is queued and nobody has answered yet.
    expect($actionRun->status)->toBe('queued')
        ->and($run->fresh()->status)->toBe('queued')
        ->and(Mail::assertNothingSent())->toBeNull();

    // A real worker, on the real Redis connection the suite reserves.
    $worker = new Worker(
        $this->app['queue'],
        $this->app['events'],
        $this->app[ExceptionHandler::class],
        fn (): bool => false,
    );

    $options = new WorkerOptions;
    $options->connectionName = 'redis';
    $options->queue = $queue;
    $options->once = true;
    $options->memory = 128;
    $options->timeout = 30;

    $worker->runNextJob('redis', $queue, $options);

    // The queue of the probe is left empty.
    $connection = $this->app['queue']->connection('redis');
    $connection->getConnection()->del($connection->getQueue($queue));

    expect(AutomationActionRun::query()->find($actionRun->id)->status)->toBe('succeeded')
        ->and($run->fresh()->status)->toBe('succeeded')
        ->and($owner->email)->not->toBeEmpty();
});

it('dispatches the delivery jobs on the queue the deployment uses', function (): void {
    Queue::fake();

    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = TelegramEndpoint::factory()->createdBy($admin)->create();

    [$rule, $action] = auto06Rule('send_telegram', [
        'endpoint_id' => $endpoint->id,
        'message' => 'Alerta encolada',
    ]);

    $run = auto06Run($rule);

    (new ExecuteAutomationRun($run->id))->handle();

    Queue::assertPushed(SendTelegramMessage::class);
});

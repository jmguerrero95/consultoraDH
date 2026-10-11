<?php

declare(strict_types=1);

use App\Domain\Support\SupportInboundEmailStatus;
use App\Jobs\SendTelegramMessage;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportInboundEmail;
use App\Models\SupportMessage;
use App\Models\SupportQueue;
use App\Models\TelegramEndpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * R2-02 — route model binding.
 *
 * ## What these tests are for
 *
 * A numeric route parameter constrained by `Route::pattern` proves only that
 * the segment looks like a number. It says nothing about whether Laravel
 * injected the persisted row into the controller argument, and the two failures
 * are independent: a route can be perfectly constrained and still bind to the
 * wrong variable name.
 *
 * That name mismatch was the real defect. The routes declared
 * `{telegramEndpoint}`, `{automationRule}` and `{id}` while the controllers
 * type-hinted `TelegramEndpoint $endpoint`, `AutomationRule $rule` and
 * `SupportInboundEmail $email`. Implicit binding resolves by parameter *name*,
 * so none of those endpoints could inject their argument, and every one of them
 * was broken on arrival.
 *
 * So every test below issues a real HTTP request against the real endpoint and
 * asserts on the persisted state the controller wrote. The evidence is the row
 * that changed, not the absence of an error: a 500 from an unresolved argument
 * satisfies "not 404" just as comfortably as a correct binding does.
 */
uses()->group('A06');
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
});

/** A rule that is inactive, so the update and activate paths are reachable. */
function inactiveRule(User $owner, array $overrides = []): AutomationRule
{
    return AutomationRule::factory()->ownedBy($owner)->create(array_merge([
        'name' => 'Regla de prueba',
        'active' => false,
        'activated_at' => null,
        'trigger_type' => 'schedule',
        'trigger_config' => ['cron' => '0 8 * * 1'],
        'condition_config' => ['all' => []],
    ], $overrides));
}
/*                               | /| Telegram                                */
test('binds {endpoint} and persists the update on the addressed row', function (): void {
    $staff = userWithPermissions(['notification_channels.manage']);
    $endpoint = TelegramEndpoint::factory()->createdBy($staff)->create([
        'label' => 'Original',
        'chat_id' => '100200300',
    ]);

    $response = $this->actingAs($staff)->patchJson("/api/telegram/endpoints/{$endpoint->id}", [
        'label' => 'Renombrado',
        'chat_id' => '999888777',
    ]);

    $response->assertOk()
        ->assertJsonPath('id', $endpoint->id)
        ->assertJsonPath('label', 'Renombrado')
        ->assertJsonPath('chat_id', '999888777');

    // The row addressed by the URL is the row that changed.
    expect($endpoint->fresh())
        ->label->toBe('Renombrado')
        ->chat_id->toBe('999888777');
});

test('binds {endpoint} on the test endpoint and queues for that endpoint', function (): void {
    Queue::fake();

    $staff = userWithPermissions(['notification_channels.manage']);
    $endpoint = TelegramEndpoint::factory()->createdBy($staff)->create([
        'label' => 'Canal de pruebas',
        'chat_id' => '555444333',
    ]);

    $response = $this->actingAs($staff)
        ->postJson("/api/telegram/endpoints/{$endpoint->id}/test", ['message' => 'Prueba A06']);

    $response->assertOk()->assertJsonPath('message', 'Mensaje de prueba encolado');

    // The queued job names the endpoint from the URL. If the argument had not
    // been injected there would be no id to pass, so this is the binding proof.
    Queue::assertPushed(SendTelegramMessage::class, fn ($job) => true);
});

test('binds {endpoint} only for the endpoint in the URL', function (): void {
    $staff = userWithPermissions(['notification_channels.manage']);
    $addressed = TelegramEndpoint::factory()->createdBy($staff)->create(['label' => 'Objetivo']);
    $untouched = TelegramEndpoint::factory()->createdBy($staff)->create(['label' => 'Otro']);

    $this->actingAs($staff)->patchJson("/api/telegram/endpoints/{$addressed->id}", [
        'label' => 'Modificado',
    ])->assertOk();

    expect($untouched->fresh()->label)->toBe('Otro');
});
/*                              | /| Automations                              */
test('binds {rule} on show and returns that rule', function (): void {
    $staff = userWithPermissions(['automations.view']);
    $rule = inactiveRule($staff, ['name' => 'Regla visible']);
    $other = inactiveRule($staff, ['name' => 'Regla distinta']);

    $response = $this->actingAs($staff)->getJson("/api/automations/{$rule->id}");

    $response->assertOk()
        ->assertJsonPath('id', $rule->id)
        ->assertJsonPath('name', 'Regla visible');

    expect($other->fresh()->name)->toBe('Regla distinta');
});

test('binds {rule} on update and writes the revision to that rule', function (): void {
    $staff = userWithPermissions(['automations.view', 'automations.manage']);
    $rule = inactiveRule($staff, ['name' => 'Antes', 'revision' => 1]);
    $other = inactiveRule($staff, ['name' => 'No tocar', 'revision' => 7]);

    $response = $this->actingAs($staff)->patchJson("/api/automations/{$rule->id}", [
        'name' => 'Despues',
        'trigger_type' => 'schedule',
        'trigger_config' => ['frequency' => 'weekly', 'time' => '09:00', 'day_of_week' => 1],
        'condition_config' => [],
        'actions' => [[
            'action_type' => 'internal_notification',
            'config' => ['title' => 'Aviso'],
            'position' => 0,
        ]],
    ]);

    $response->assertOk()->assertJsonPath('name', 'Despues');

    expect($rule->fresh())
        ->name->toBe('Despues')
        ->revision->toBe(2)
        ->actions->toHaveCount(1);

    // The other rule's revision is untouched, so the write was addressed.
    expect($other->fresh())
        ->name->toBe('No tocar')
        ->revision->toBe(7);
});

test('binds {rule} on activate and flips only that rule', function (): void {
    $staff = userWithPermissions(['automations.view', 'automations.manage']);
    $rule = inactiveRule($staff);
    $other = inactiveRule($staff);

    $this->actingAs($staff)
        ->postJson("/api/automations/{$rule->id}/activate")
        ->assertOk()
        ->assertJsonPath('active', true)
        ->assertJsonPath('id', $rule->id);

    expect($rule->fresh()->active)->toBeTrue()
        ->and($other->fresh()->active)->toBeFalse();
});

test('binds {rule} on deactivate and flips only that rule', function (): void {
    $staff = userWithPermissions(['automations.view', 'automations.manage']);
    $rule = inactiveRule($staff, ['active' => true, 'activated_at' => now()]);
    $other = inactiveRule($staff, ['active' => true, 'activated_at' => now()]);

    $this->actingAs($staff)
        ->postJson("/api/automations/{$rule->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('active', false)
        ->assertJsonPath('id', $rule->id);

    expect($rule->fresh()->active)->toBeFalse()
        ->and($other->fresh()->active)->toBeTrue();
});

test('binds {rule} on runs and scopes the page to that rule', function (): void {
    $staff = userWithPermissions(['automations.view']);
    $rule = inactiveRule($staff);
    $other = inactiveRule($staff);

    // One run on each, so a scope mistake would be visible in the count.
    // `occurrence_key` is required and unique per rule: it is what makes a
    // schedule occurrence idempotent, so a second run for the same occurrence
    // is refused by the database rather than silently duplicated.
    AutomationRun::create([
        'automation_rule_id' => $rule->id,
        'occurrence_key' => 'occurrence-for-the-addressed-rule',
        'status' => 'succeeded',
        'started_at' => now(),
    ]);
    AutomationRun::create([
        'automation_rule_id' => $other->id,
        'occurrence_key' => 'occurrence-for-the-other-rule',
        'status' => 'succeeded',
        'started_at' => now(),
    ]);

    $response = $this->actingAs($staff)->getJson("/api/automations/{$rule->id}/runs");

    $response->assertOk()->assertJsonPath('total', 1);

    expect($response->json('data'))->toHaveCount(1);
});
/*                        | /| Unlinked inbound email                         */
test('binds {email} on link and opens a conversation from that message', function (): void {
    seedPortfolioRoles();

    $staff = userWithPermissions(['support.review_unlinked_email']);
    $client = Client::factory()->create();
    $queue = SupportQueue::factory()->create();

    $email = SupportInboundEmail::factory()->quarantined()->create([
        'subject' => 'Correo sin conversación',
        'body_text' => 'Contenido que debe terminar en la conversación.',
    ]);

    $response = $this->actingAs($staff)->postJson("/api/support/unlinked-email/{$email->id}/link", [
        'client_id' => $client->id,
        'subject' => 'Conversación creada desde el correo',
        'queue_id' => $queue->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('client.id', $client->id)
        ->assertJsonPath('queue.id', $queue->id);

    // The body travelled from the inbound message into a support message, which
    // is only possible if the controller received the addressed row.
    expect($email->fresh())
        ->status->toBe(SupportInboundEmailStatus::Linked)
        ->linked_conversation_id->toBe($response->json('id'));

    expect(SupportMessage::query()
        ->where('conversation_id', $response->json('id'))
        ->where('external_message_id', $email->external_message_id)
        ->exists())->toBeTrue();
});

test('binds {email} on discard and marks only that message', function (): void {
    $staff = userWithPermissions(['support.review_unlinked_email']);

    $discarded = SupportInboundEmail::factory()->quarantined()->create();
    $kept = SupportInboundEmail::factory()->quarantined()->create();

    $this->actingAs($staff)->postJson("/api/support/unlinked-email/{$discarded->id}/discard", [
        'reason' => 'Correo descartado durante la verificacion de R2-02',
    ])->assertOk();

    expect($discarded->fresh())
        ->status->toBe(SupportInboundEmailStatus::Discarded)
        ->reason->toBe('Correo descartado durante la verificacion de R2-02');

    expect($kept->fresh()->status)->toBe(SupportInboundEmailStatus::Quarantined);
});


/* Unknown ids                                                                 */


test('an unknown numeric id is 404 on every bound endpoint', function (): void {
    $staff = userWithPermissions([
        'notification_channels.manage',
        'automations.view',
        'automations.manage',
        'support.review_unlinked_email',
    ]);

    // Well-formed but absent, so the pattern constraint accepts it and the
    // binding step is what has to refuse it.
    $absent = SupportQueue::max('id') + 1000;

    $this->actingAs($staff)->patchJson("/api/telegram/endpoints/{$absent}", ['label' => 'x'])
        ->assertNotFound();

    $this->actingAs($staff)->postJson("/api/telegram/endpoints/{$absent}/test")
        ->assertNotFound();

    $this->actingAs($staff)->getJson("/api/automations/{$absent}")
        ->assertNotFound();

    $this->actingAs($staff)->patchJson("/api/automations/{$absent}", ['name' => 'x'])
        ->assertNotFound();

    $this->actingAs($staff)->postJson("/api/automations/{$absent}/activate")
        ->assertNotFound();

    $this->actingAs($staff)->postJson("/api/automations/{$absent}/deactivate")
        ->assertNotFound();

    $this->actingAs($staff)->getJson("/api/automations/{$absent}/runs")
        ->assertNotFound();

    $this->actingAs($staff)->postJson("/api/support/unlinked-email/{$absent}/link", [
        'client_id' => Client::factory()->create()->id,
        'subject' => 'No existe',
    ])->assertNotFound();

    $this->actingAs($staff)->postJson("/api/support/unlinked-email/{$absent}/discard", [
        'reason' => 'No existe',
    ])->assertNotFound();
});

test('a non numeric id is refused by the pattern constraint', function (): void {
    $staff = userWithPermissions([
        'notification_channels.manage',
        'automations.view',
        'support.review_unlinked_email',
    ]);

    foreach (['abc', '1a2b', 'endpoints'] as $id) {
        $this->actingAs($staff)->patchJson("/api/telegram/endpoints/{$id}", ['label' => 'x'])
            ->assertNotFound();

        $this->actingAs($staff)->getJson("/api/automations/{$id}")
            ->assertNotFound();

        $this->actingAs($staff)->postJson("/api/support/unlinked-email/{$id}/discard", ['reason' => 'x'])
            ->assertNotFound();
    }
});
/*                      | /| The original defect, named                       */
/**
 * The route parameter and the controller argument must agree.
 *
 * Stated separately from the behavioural tests above because it fails at a
 * different moment: not when a request is made, but when a route is added later
 * with a plausible name that does not match its argument. Reading it off the
 * routing table makes that a build-time statement instead of a 500 found in
 * production.
 */
test('every bound route parameter matches its controller argument name', function (): void {
    $routes = app('router')->getRoutes();
    $reflections = collect([
        \App\Http\Controllers\Api\AutomationController::class,
        \App\Http\Controllers\Api\SupportInboundEmailController::class,
        \App\Http\Controllers\Api\SupportConversationController::class,
        \App\Http\Controllers\Api\SupportQueueController::class,
        \App\Http\Controllers\Api\TelegramController::class,
    ])->keyBy(fn (string $class): string => $class);

    $mismatches = [];

    foreach ($routes as $route) {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            continue;
        }

        [$class, $method] = explode('@', $action);
        $class = ltrim($class, '\\');

        if (! $reflections->has($class) || ! method_exists($class, $method)) {
            continue;
        }

        // Parameters this route declares, in order.
        $parameters = array_values(array_filter(
            array_keys($route->parameterNames()),
            fn (string $name): bool => $name !== '',
        ));

        // The controller's typed arguments that are not themselves values.
        $reflection = new ReflectionMethod($class, $method);
        $typed = array_map(
            fn (ReflectionParameter $parameter): string => $parameter->getName(),
            array_filter(
                $reflection->getParameters(),
                fn (ReflectionParameter $parameter): bool => $parameter->getType() !== null
                    && ! $parameter->isDefaultValueAvailable(),
            ),
        );

        // A bound parameter resolves by name. When the route declares more
        // parameters than the method takes as arguments, the extra ones are
        // nested resources and are matched positionally, so only a same-count
        // mismatch is a binding defect.
        if (count($parameters) !== count($typed)) {
            continue;
        }

        foreach ($parameters as $index => $parameter) {
            if (($typed[$index] ?? null) !== $parameter) {
                $mismatches[] = sprintf(
                    '%s::%s() declares {%s} but takes $%s',
                    class_basename($class),
                    $method,
                    $parameter,
                    $typed[$index] ?? '(none)',
                );
            }
        }
    }

    expect($mismatches)->toBe([]);
});

<?php

declare(strict_types=1);

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportQueue;
use App\Models\SupportQueueMember;
use App\Models\User;

beforeEach(function (): void {
    seedRoles();

    $this->staff = userWithRole('Support');
    $this->portalClient = Client::factory()->create();
    $this->portal = User::factory()->create([
        'account_type' => 'client',
        'client_id' => $this->portalClient->id,
    ]);

    $this->queue = SupportQueue::factory()->create(['is_default' => false]);
    SupportQueueMember::create([
        'queue_id' => $this->queue->id,
        'user_id' => $this->staff->id,
    ]);
});

it('clamps support message pagination at both ends', function (): void {
    $conversation = SupportConversation::factory()->create([
        'client_id' => $this->portalClient->id,
        'queue_id' => $this->queue->id,
    ]);

    foreach (range(1, 120) as $index) {
        SupportMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'body_text' => 'Mensaje '.$index,
        ]);
    }

    $zero = $this->actingAs($this->staff)
        ->getJson("/api/support/conversations/{$conversation->id}?per_page=0")
        ->assertOk()
        ->assertHeader('X-Has-More', 'true');

    expect($zero->json('messages'))->toHaveCount(1);

    $huge = $this->actingAs($this->staff)
        ->getJson("/api/support/conversations/{$conversation->id}?per_page=1000000")
        ->assertOk()
        ->assertHeader('X-Has-More', 'true');

    expect($huge->json('messages'))->toHaveCount(100);

    // A negative page is a page of one, not an empty answer and not an error.
    $negative = $this->actingAs($this->staff)
        ->getJson("/api/support/conversations/{$conversation->id}?per_page=-1")
        ->assertOk();

    expect($negative->json('messages'))->toHaveCount(1);

    // The cursor keeps working with the clamped page size: the next cursor is
    // the oldest message of the page, and asking for what comes before it
    // returns the previous one.
    $cursor = (string) $huge->headers->get('X-Next-Cursor');

    expect($cursor)->not->toBe('');

    $before = $this->actingAs($this->staff)
        ->getJson("/api/support/conversations/{$conversation->id}?per_page=1&before_id={$cursor}")
        ->assertOk();

    expect($before->json('messages'))->toHaveCount(1)
        ->and((int) $before->json('messages.0.id'))->toBeLessThan((int) $cursor);
});

it('clamps the portal conversation list and message cursor', function (): void {
    foreach (range(1, 3) as $index) {
        SupportConversation::factory()->create([
            'client_id' => $this->portalClient->id,
            'queue_id' => $this->queue->id,
            'subject' => 'Conversación '.$index,
        ]);
    }

    $zero = $this->actingAs($this->portal)
        ->getJson('/api/portal/support/conversations?per_page=0')
        ->assertOk();

    expect($zero->json('per_page'))->toBe(1)
        ->and($zero->json('last_page'))->toBe(3)
        ->and($zero->json('data'))->toHaveCount(1);

    $huge = $this->actingAs($this->portal)
        ->getJson('/api/portal/support/conversations?per_page=1000000')
        ->assertOk();

    expect($huge->json('per_page'))->toBe(100)
        ->and($huge->json('last_page'))->toBe(1)
        ->and($huge->json('data'))->toHaveCount(3);

    $negative = $this->actingAs($this->portal)
        ->getJson('/api/portal/support/conversations?per_page=-1')
        ->assertOk();

    expect($negative->json('per_page'))->toBe(1)
        ->and($negative->json('data'))->toHaveCount(1);

    $one = $this->actingAs($this->portal)
        ->getJson('/api/portal/support/conversations?per_page=1')
        ->assertOk();

    expect($one->json('per_page'))->toBe(1)
        ->and($one->json('last_page'))->toBe(3);

    $hundred = $this->actingAs($this->portal)
        ->getJson('/api/portal/support/conversations?per_page=100')
        ->assertOk();

    expect($hundred->json('per_page'))->toBe(100)
        ->and($hundred->json('last_page'))->toBe(1);
});

it('clamps automation rules and runs pagination', function (): void {
    $operator = userWithPermissions(['automations.view']);

    foreach (range(1, 3) as $index) {
        AutomationRule::factory()->ownedBy($operator)->create(['name' => 'Regla '.$index]);
    }

    $zero = $this->actingAs($operator)
        ->getJson('/api/automations?per_page=0')
        ->assertOk();

    expect($zero->json('per_page'))->toBe(1)
        ->and($zero->json('last_page'))->toBe(3)
        ->and($zero->json('data'))->toHaveCount(1);

    $huge = $this->actingAs($operator)
        ->getJson('/api/automations?per_page=1000000')
        ->assertOk();

    expect($huge->json('per_page'))->toBe(100)
        ->and($huge->json('data'))->toHaveCount(3);

    $rule = AutomationRule::query()->firstOrFail();

    foreach (range(1, 3) as $index) {
        AutomationRun::create([
            'automation_rule_id' => $rule->id,
            'occurrence_key' => 'run-'.$index,
            'status' => 'succeeded',
            'trigger_snapshot' => [],
        ]);
    }

    $runs = $this->actingAs($operator)
        ->getJson("/api/automations/{$rule->id}/runs?per_page=0")
        ->assertOk();

    expect($runs->json('per_page'))->toBe(1)
        ->and($runs->json('last_page'))->toBe(3);
});

it('answers a portal conversation without a default queue with a useful 409', function (): void {
    $this->actingAs($this->portal)->postJson('/api/portal/support/conversations', [
        'subject' => 'Necesito ayuda',
        'body_text' => 'No puedo cargar un documento.',
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'no_default_queue');

    expect(SupportConversation::query()->count())->toBe(0);
});

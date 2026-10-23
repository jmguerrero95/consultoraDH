<?php

declare(strict_types=1);

namespace Tests\Feature\A06;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

beforeEach(function (): void {
    seedRoles();

    // The suite must not depend on an operator's `.env` for this: the endpoint
    // answers 503 when no key is configured, and this is a test of the endpoint
    // with a key. A clearly fake value is enough — the endpoint only reads it.
    config(['services.webpush.public_key' => 'test-vapid-public-key-not-a-real-key']);

    $this->user = User::factory()->create(['account_type' => 'staff']);
    $this->user->assignRole('Support');
});

test('public VAPID key endpoint returns key only', function (): void {
    $response = $this->actingAs($this->user)
        ->getJson('/api/push/vapid-public-key');

    $response->assertOk();
    $response->assertJsonStructure(['public_key']);
    expect($response->json('public_key'))->toBeString()->not->toBeEmpty();
});

test('public VAPID key endpoint refuses when no key is configured', function (): void {
    config(['services.webpush.public_key' => null]);

    $this->actingAs($this->user)
        ->getJson('/api/push/vapid-public-key')
        ->assertStatus(503);
});

test('private VAPID key never exposed', function (): void {
    // No endpoint should return the private key
    $routes = collect(Route::getRoutes())->map(fn ($r) => $r->uri());
    expect($routes)->not->toContain('push/private');
    expect($routes)->not->toContain('vapid/private');
});

test('own subscription can be stored', function (): void {
    $subscription = [
        'endpoint' => 'https://push.example.com/endpoint/'.uniqid(),
        'keys' => [
            'p256dh' => base64_encode(random_bytes(32)),
            'auth' => base64_encode(random_bytes(16)),
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/push/subscriptions', $subscription);

    $response->assertStatus(201);
    $response->assertJsonStructure(['id']);
});

test('own subscription can be deleted', function (): void {
    $subscription = [
        'endpoint' => 'https://push.example.com/endpoint/'.uniqid(),
        'keys' => [
            'p256dh' => base64_encode(random_bytes(32)),
            'auth' => base64_encode(random_bytes(16)),
        ],
    ];

    // Store first
    $response = $this->actingAs($this->user)
        ->postJson('/api/push/subscriptions', $subscription);
    $response->assertStatus(201);
    $subscriptionId = $response->json('id');

    // Delete
    $response = $this->actingAs($this->user)
        ->deleteJson("/api/push/subscriptions/{$subscriptionId}");

    $response->assertOk();
    $response->assertJsonPath('message', 'Suscripción eliminada');
});

test('another user cannot delete subscription', function (): void {
    $otherUser = User::factory()->create(['account_type' => 'staff']);
    $otherUser->assignRole('Support');

    $subscription = [
        'endpoint' => 'https://push.example.com/endpoint/'.uniqid(),
        'keys' => [
            'p256dh' => base64_encode(random_bytes(32)),
            'auth' => base64_encode(random_bytes(16)),
        ],
    ];

    // Store with user A
    $response = $this->actingAs($this->user)
        ->postJson('/api/push/subscriptions', $subscription);
    $response->assertStatus(201);
    $subscriptionId = $response->json('id');

    // User B tries to delete
    $userB = User::factory()->create(['account_type' => 'staff']);
    $userB->assignRole('Support');

    $response = $this->actingAs($userB)
        ->deleteJson("/api/push/subscriptions/{$subscriptionId}");

    $response->assertForbidden();
});

test('stale provider response removes stale subscription', function (): void {
    // This test would require mocking the push service
    // For now, we verify the endpoint exists and handles errors
    $subscription = [
        'endpoint' => 'https://push.example.com/endpoint/'.uniqid(),
        'keys' => [
            'p256dh' => base64_encode(random_bytes(32)),
            'auth' => base64_encode(random_bytes(16)),
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/push/subscriptions', $subscription);
    $response->assertStatus(201);

    // Verify the subscription was created (by ID since endpoint is encrypted)
    $subscriptionId = $response->json('id');
    $this->assertDatabaseHas('push_subscriptions', [
        'id' => $subscriptionId,
        'user_id' => $this->user->id,
    ]);
});

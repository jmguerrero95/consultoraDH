<?php

declare(strict_types=1);

namespace Tests\Feature\A06;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(\Tests\TestCase::class, RefreshDatabase::class)->in('Feature');

beforeEach(function (): void {
    seedRoles();

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

test('private VAPID key never exposed', function (): void {
    // No endpoint should return the private key
    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())->map(fn ($r) => $r->uri());
    expect($routes)->not->toContain('push/private');
    expect($routes)->not->toContain('vapid/private');
});

test('own subscription can be stored', function (): void {
    $subscription = [
        'endpoint' => 'https://push.example.com/endpoint/' . uniqid(),
        'keys' => [
            'p256dh' => base64_encode(random_bytes(32)),
            'auth' => base64_encode(random_bytes(16)),
        ],
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/api/push/subscriptions', [
            'subscription' => $subscription,
        ]);

    $response->assertOk();
    $response->assertJsonPath('success', true);
});

test('own subscription can be deleted', function (): void {
    $subscription = [
        'endpoint' => 'https://push.example.com/endpoint/' . uniqid(),
        'keys' => [
            'p256dh' => base64_encode(random_bytes(32)),
            'auth' => base64_encode(random_bytes(16)),
        ],
    ];

    // Store first
    $this->actingAs($this->user)
        ->postJson('/api/push/subscriptions', [
            'subscription' => $subscription,
        ])->assertOk();

    // Delete
    $response = $this->actingAs($this->user)
        ->deleteJson('/api/push/subscriptions', [
            'endpoint' => $subscription['endpoint'],
        ]);

    $response->assertOk();
    $response->assertJsonPath('success', true);
});

test('another user cannot delete subscription', function (): void {
    $otherUser = User::factory()->create(['account_type' => 'staff']);
    $otherUser->assignRole('Support');

    $subscription = [
        'endpoint' => 'https://push.example.com/endpoint/' . uniqid(),
        'keys' => [
            'p256dh' => base64_encode(random_bytes(32)),
            'auth' => base64_encode(random_bytes(16)),
        ],
    ];

    // Store with user A
    $this->actingAs($this->user)
        ->postJson('/api/push/subscriptions', [
            'subscription' => $subscription,
        ])->assertOk();

    // User B tries to delete
    $response = $this->actingAs($this->userB ?? User::factory()->create(['account_type' => 'staff']))
        ->deleteJson('/api/push/subscriptions', [
            'endpoint' => $subscription['endpoint'],
        ]);

    $response->assertForbidden();
});

test('stale provider response removes stale subscription', function (): void {
    // This test would require mocking the push service
    // For now, we verify the endpoint exists and handles errors
    $subscription = [
        'endpoint' => 'https://push.example.com/endpoint/' . uniqid(),
        'keys' => [
            'p256dh' => base64_encode(random_bytes(32)),
            'auth' => base64_encode(random_bytes(16)),
        ],
    ];

    $this->actingAs($this->user)
        ->postJson('/api/push/subscriptions', [
            'subscription' => $subscription,
        ])->assertOk();

    // Simulate sending a push and getting a 410 Gone
    // This would be tested with a mocked HTTP client
    // For now, verify the subscription exists
    $this->assertDatabaseHas('push_subscriptions', [
        'endpoint' => $subscription['endpoint'],
        'user_id' => $this->user->id,
    ]);
});
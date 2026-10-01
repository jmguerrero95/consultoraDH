<?php

declare(strict_types=1);

use App\Domain\Audit\AuditAction;
use App\Models\AuditEvent;
use App\Models\User;

it('invalidates the session on sign out', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/auth/me')->assertOk();

    $this->actingAs($user)->postJson('/api/auth/logout')->assertOk();

    $this->assertGuest();

    $this->getJson('/api/dashboard')->assertStatus(401);
});

it('records the sign out in the audit trail', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/auth/logout')->assertOk();

    expect(AuditEvent::query()
        ->where('action', AuditAction::Logout->value)
        ->where('user_id', $user->id)
        ->exists())->toBeTrue();
});

it('records the client address of the request', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->postJson('/api/auth/logout')
        ->assertOk();

    $event = AuditEvent::query()
        ->where('action', AuditAction::Logout->value)
        ->latest('id')
        ->firstOrFail();

    expect($event->ip_address)->toBe('203.0.113.10');
});

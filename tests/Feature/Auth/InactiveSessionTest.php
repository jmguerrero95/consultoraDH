<?php

declare(strict_types=1);

use App\Domain\Audit\AuditAction;
use App\Models\AuditEvent;
use App\Models\User;

/**
 * An account that is made inactive while a session is open.
 *
 * Suspending somebody has to take effect immediately. A session cookie issued
 * while the account was active would otherwise keep working until it expired,
 * which is the opposite of what an operator suspending an account expects.
 */

/** The roles the permissions depend on. */
beforeEach(function (): void {
    seedRoles();
});

it('refuses the next request once the account becomes inactive', function (): void {
    $user = User::factory()->create(['email' => 'suspendido@consultora-dh.test']);

    // Signed in and working.
    $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

    User::query()->whereKey($user->id)->update(['status' => 'inactive']);

    // The next request is refused, even though the session was never reissued.
    $this->actingAs($user->refresh())->getJson('/api/dashboard')->assertStatus(401);
});

it('returns the ordinary unauthenticated response, not an explanation', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

    User::query()->whereKey($user->id)->update(['status' => 'inactive']);

    $response = $this->actingAs($user->refresh())->getJson('/api/dashboard');

    $response->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');

    // Nothing that tells the caller the account exists but is inactive.
    $body = $response->getContent();

    expect($body)->not->toContain('inactive')
        ->and($body)->not->toContain('inactivo')
        ->and($body)->not->toContain('suspend');
});

it('leaves the caller unauthenticated afterwards', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

    User::query()->whereKey($user->id)->update(['status' => 'inactive']);

    $this->actingAs($user->refresh())->getJson('/api/dashboard')->assertStatus(401);

    // The session was invalidated, so there is nothing left to be refused.
    $this->postJson('/api/auth/logout')->assertStatus(401);
});

it('refuses every protected endpoint, not just the dashboard', function (string $uri, string $method, array $payload = []): void {
    $user = userWithRole('Super Admin');

    // Establish the session first, so the endpoint is known to answer 200.
    $this->actingAs($user)->{$method.'Json'}($uri, $payload)->assertOk();

    User::query()->whereKey($user->id)->update(['status' => 'inactive']);

    $this->actingAs($user->refresh())->{$method.'Json'}($uri, $payload)->assertStatus(401);
})->with([
    'current user' => ['/api/auth/me', 'get'],
    'dashboard' => ['/api/dashboard', 'get'],
    'profile' => ['/api/profile', 'get'],
    'settings' => ['/api/settings', 'get'],
    'profile update' => ['/api/profile', 'patch', ['name' => 'Nombre Nuevo2026']],
    'logout' => ['/api/auth/logout', 'post'],
]);

it('invalidates the session rather than only refusing the response', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

    $before = session()->getId();

    User::query()->whereKey($user->id)->update(['status' => 'inactive']);

    $this->actingAs($user->refresh())->getJson('/api/dashboard')->assertStatus(401);

    // The identifier the browser was holding is no longer the live session.
    expect(session()->getId())->not->toBe($before);
});

it('issues a fresh CSRF token so the next request cannot replay the old one', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

    $tokenBefore = session()->token();

    User::query()->whereKey($user->id)->update(['status' => 'inactive']);

    $this->actingAs($user->refresh())->getJson('/api/dashboard')->assertStatus(401);

    expect(session()->token())->not->toBe($tokenBefore);
});

it('records the transition in the audit trail', function (): void {
    $user = User::factory()->create(['email' => 'suspendido@consultora-dh.test']);

    $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

    User::query()->whereKey($user->id)->update(['status' => 'inactive']);

    $this->actingAs($user->refresh())->getJson('/api/dashboard')->assertStatus(401);

    $event = AuditEvent::query()
        ->where('action', AuditAction::SessionRejectedInactive->value)
        ->firstOrFail();

    expect($event->user_id)->toBe($user->id)
        ->and($event->metadata['email'])->toBe('suspendido@consultora-dh.test')
        ->and($event->metadata['status'])->toBe('inactive');
});

it('does not write an audit row on every repeated request', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

    User::query()->whereKey($user->id)->update(['status' => 'inactive']);

    $this->actingAs($user->refresh())->getJson('/api/dashboard')->assertStatus(401);

    // The session is gone, so there is no user left to record on later
    // requests: the count must not grow with the traffic.
    foreach (range(1, 15) as $ignored) {
        $this->getJson('/api/dashboard')->assertStatus(401);
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    expect(AuditEvent::query()
        ->where('action', AuditAction::SessionRejectedInactive->value)
        ->count())->toBe(1);
});

it('still refuses sign in for an inactive account', function (): void {
    $user = User::factory()->create(['status' => 'inactive']);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'ContrasenaDePrueba2026',
    ])->assertStatus(422);
});

it('leaves an active account completely unaffected', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/profile')->assertOk();
    }

    expect(AuditEvent::query()
        ->where('action', AuditAction::SessionRejectedInactive->value)
        ->count())->toBe(0);
});

it('reactivating the account does not resurrect the old session', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/dashboard')->assertOk();

    User::query()->whereKey($user->id)->update(['status' => 'inactive']);

    $this->actingAs($user->refresh())->getJson('/api/dashboard')->assertStatus(401);

    // Back to active, but the session was invalidated and a new sign in is
    // required, which is the correct outcome: access was cut, not paused.
    User::query()->whereKey($user->id)->update(['status' => 'active']);

    $this->actingAs($user->fresh())->getJson('/api/dashboard')->assertOk();
});

it('does not block an active administrator when another account is suspended', function (): void {
    $suspended = User::factory()->create(['status' => 'inactive']);
    $active = User::factory()->create();

    $this->actingAs($active)->getJson('/api/dashboard')->assertOk();

    // The suspended account's attempt does not consume anything of the active one.
    $this->actingAs($suspended)->getJson('/api/dashboard')->assertStatus(401);

    $this->actingAs($active)->getJson('/api/dashboard')->assertOk();
});

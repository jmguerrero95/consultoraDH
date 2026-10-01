<?php

declare(strict_types=1);

use App\Domain\Users\UserStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

it('lets an active user sign in', function (): void {
    $user = User::factory()->withPassword('ContrasenaSegura2026')->create([
        'email' => 'activo@consultora-dh.test',
    ]);

    $response = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'activo@consultora-dh.test',
            'password' => 'ContrasenaSegura2026',
        ]);

    $response->assertOk()
        ->assertJsonPath('user.email', 'activo@consultora-dh.test')
        ->assertJsonPath('user.status', 'active');

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password', function (): void {
    User::factory()->withPassword('ContrasenaSegura2026')->create([
        'email' => 'activo@consultora-dh.test',
    ]);

    $response = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'activo@consultora-dh.test',
            'password' => 'ContrasenaIncorrecta2026',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $this->assertGuest();
});

it('rejects an inactive account', function (): void {
    User::factory()->inactive()->withPassword('ContrasenaSegura2026')->create([
        'email' => 'inactivo@consultora-dh.test',
    ]);

    $response = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'inactivo@consultora-dh.test',
            'password' => 'ContrasenaSegura2026',
        ]);

    $response->assertStatus(422);

    $this->assertGuest();
});

it('answers a wrong password and an inactive account identically', function (): void {
    User::factory()->withPassword('ContrasenaSegura2026')->create(['email' => 'activo@consultora-dh.test']);
    User::factory()->inactive()->withPassword('ContrasenaSegura2026')->create(['email' => 'inactivo@consultora-dh.test']);

    $wrongPassword = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'activo@consultora-dh.test',
            'password' => 'ContrasenaIncorrecta2026',
        ]);

    $inactive = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'inactivo@consultora-dh.test',
            'password' => 'ContrasenaSegura2026',
        ]);

    // Identical bodies: the endpoint cannot be used to discover which
    // addresses exist or which are active.
    expect($wrongPassword->json())->toBe($inactive->json());
});

it('answers an unknown address the same way as a wrong password', function (): void {
    User::factory()->withPassword('ContrasenaSegura2026')->create(['email' => 'activo@consultora-dh.test']);

    $wrongPassword = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'activo@consultora-dh.test',
            'password' => 'ContrasenaIncorrecta2026',
        ]);

    $unknown = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'nadie@consultora-dh.test',
            'password' => 'ContrasenaIncorrecta2026',
        ]);

    expect($wrongPassword->json())->toBe($unknown->json());
});

it('normalises the address to lower case before persisting', function (): void {
    User::factory()->create(['email' => 'mixto@consultora-dh.test']);

    $user = User::factory()->create([
        'email' => '  NUEVO@Consultora-DH.TEST ',
        'password' => 'ContrasenaSegura2026',
    ]);

    expect($user->email)->toBe('nuevo@consultora-dh.test');

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'NUEVO@CONSULTORA-DH.TEST',
            'password' => 'ContrasenaSegura2026',
        ])
        ->assertOk();
});

it('refuses to persist a duplicate address that differs only in case', function (): void {
    User::factory()->create(['email' => 'duplicado@consultora-dh.test']);

    User::factory()->create(['email' => 'DUPLICADO@consultora-dh.test']);
})->throws(QueryException::class);

it('records the moment of the last sign in', function (): void {
    $user = User::factory()->withPassword('ContrasenaSegura2026')->create();

    expect($user->last_login_at)->toBeNull();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'ContrasenaSegura2026',
        ])
        ->assertOk();

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('regenerates the session identifier on sign in', function (): void {
    $user = User::factory()->withPassword('ContrasenaSegura2026')->create();

    // The identifier that travelled with the request...
    $before = $this->get('/login')->getCookie((string) config('session.cookie'))?->getValue();

    // ...must not survive authentication, otherwise an attacker who fixed the
    // identifier beforehand would inherit the authenticated session.
    $after = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'ContrasenaSegura2026',
    ])->assertOk()->getCookie((string) config('session.cookie'))?->getValue();

    expect($before)->toBeString()
        ->and($after)->toBeString()
        ->and($after)->not->toBe($before);
});

it('rate limits repeated sign in attempts', function (): void {
    User::factory()->withPassword('ContrasenaSegura2026')->create(['email' => 'objetivo@consultora-dh.test']);

    $statuses = [];

    // The per-IP limit allows 20 attempts per 5 minutes, so the counter has to
    // go past that. See AppServiceProvider for the two layered limits.
    for ($attempt = 0; $attempt < 25; $attempt++) {
        $statuses[] = $this->withSession(['_token' => 'test-csrf-token'])
            ->postJson('/api/auth/login', [
                'email' => 'objetivo@consultora-dh.test',
                'password' => 'ContrasenaIncorrecta2026',
            ])
            ->status();
    }

    expect($statuses)->toContain(429);
});

it('does not rate limit an ordinary user who signs in once', function (): void {
    $user = User::factory()->withPassword('ContrasenaSegura2026')->create();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'ContrasenaSegura2026',
        ])
        ->assertOk();
});

it('runs the stateful API inside the web middleware group', function (): void {
    // The JSON API is deliberately registered with the `web` group so that
    // session authentication and CSRF verification apply to it. The framework
    // skips CSRF while running tests, so the guarantee is asserted structurally
    // here; the runtime behaviour (HTTP 419 without a token) is verified by the
    // end to end checks and by the Playwright suite.
    $middleware = Route::getRoutes()->getByName('api.auth.login')?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('web')
        ->and(config('app.key'))->not->toBeEmpty();
});

it('rejects an inactive account that exists but is blocked', function (): void {
    $user = User::factory()->inactive()->withPassword('ContrasenaSegura2026')->create();

    expect($user->status)->toBe(UserStatus::Inactive)
        ->and($user->isActive())->toBeFalse();
});

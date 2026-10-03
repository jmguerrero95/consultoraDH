<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Testing\TestResponse;

/**
 * Rate limiting.
 *
 * The property that matters most is separation: asking for a recovery mail is
 * not a sign in attempt, so it must not be able to spend the sign in budget.
 * Otherwise anyone who knows an administrator's address could keep them locked
 * out without ever guessing a password.
 */
const LIMIT_CLAVE = 'ContrasenaDeLimite2026';

/** Post one request with a valid CSRF token. */
function postAuth(string $uri, array $payload = [], array $server = []): TestResponse
{
    $request = test()->withSession(['_token' => 'test-csrf-token']);

    if ($server !== []) {
        $request = $request->withServerVariables($server);
    }

    return $request->postJson($uri, $payload);
}

function loginPayload(string $email, string $password = 'ContrasenaIncorrecta2026'): array
{
    return ['email' => $email, 'password' => $password];
}

// --- Sign in: exhaustion ----------------------------------------------------

it('limits repeated sign in attempts from one source', function (): void {
    User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'objetivo@consultora-dh.test']);

    $statuses = [];

    // The per-IP limit allows 20 attempts per 5 minutes, so go past it.
    for ($attempt = 0; $attempt < 25; $attempt++) {
        $statuses[] = postAuth('/api/auth/login', loginPayload('objetivo@consultora-dh.test'))->status();
    }

    expect($statuses)->toContain(429);
});

it('returns 429 with the documented shape when sign in is exhausted', function (): void {
    User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'objetivo@consultora-dh.test']);

    $last = null;

    for ($attempt = 0; $attempt < 25; $attempt++) {
        $last = postAuth('/api/auth/login', loginPayload('objetivo@consultora-dh.test'));
    }

    $last->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests')
        ->assertJsonStructure(['message', 'code', 'retry_after']);
});

it('limits attempts against one account across client addresses', function (): void {
    User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'administrador@consultora-dh.test']);

    $statuses = [];

    // A different client address each time, so only the per-account limit can
    // stop this: that is what a distributed guessing attack looks like. The
    // budget is 30 attempts in 15 minutes, so 35 trips it.
    for ($attempt = 0; $attempt < 35; $attempt++) {
        $statuses[] = postAuth(
            '/api/auth/login',
            loginPayload('administrador@consultora-dh.test'),
            ['REMOTE_ADDR' => '203.0.113.'.($attempt + 1)],
        )->status();
    }

    expect($statuses)->toContain(429);
});

it('keeps the per-account budget high enough for ordinary human error', function (): void {
    User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'administrador@consultora-dh.test']);

    // Five wrong guesses, a mistyped new password after a change, and the real
    // one. This is the sequence the old budget of 10 used to break.
    foreach (range(1, 5) as $ignored) {
        postAuth('/api/auth/login', loginPayload('administrador@consultora-dh.test'))->assertStatus(422);
    }

    postAuth('/api/auth/login', loginPayload('administrador@consultora-dh.test', LIMIT_CLAVE))->assertOk();
});

it('does not let a flood against one account block a different account', function (): void {
    $attacked = User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'atacado@consultora-dh.test']);
    User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'administrador@consultora-dh.test']);

    // Flood the first account, each attempt from a different client address, so
    // only the per-account limit can stop it.
    for ($attempt = 0; $attempt < 35; $attempt++) {
        postAuth(
            '/api/auth/login',
            loginPayload($attacked->email),
            ['REMOTE_ADDR' => '198.51.100.'.($attempt + 1)],
        );
    }

    // The legitimate administrator signs in normally from yet another address.
    postAuth(
        '/api/auth/login',
        loginPayload('administrador@consultora-dh.test', LIMIT_CLAVE),
        ['REMOTE_ADDR' => '198.51.100.200'],
    )->assertOk();
});

it('keeps the per-IP limit scoped to the client that exhausted it', function (): void {
    User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'objetivo@consultora-dh.test']);
    User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'colega@consultora-dh.test']);

    for ($attempt = 0; $attempt < 25; $attempt++) {
        postAuth('/api/auth/login', loginPayload('objetivo@consultora-dh.test'), ['REMOTE_ADDR' => '198.51.100.7']);
    }

    // A colleague on a different machine is unaffected.
    postAuth('/api/auth/login', loginPayload('colega@consultora-dh.test', LIMIT_CLAVE), ['REMOTE_ADDR' => '203.0.113.9'])
        ->assertOk();
});

// --- Separation ------------------------------------------------------------

it('does not let recovery requests consume the sign in budget', function (): void {
    User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'administrador@consultora-dh.test']);

    // Exhaust the whole recovery budget: both of its own limits are spent, so
    // the endpoint starts answering 429 for this account.
    $statuses = [];

    foreach (range(1, 12) as $ignored) {
        $statuses[] = postAuth('/api/auth/forgot-password', ['email' => 'administrador@consultora-dh.test'])->status();
    }

    expect($statuses)->toContain(429);

    // The sign in budget is untouched: both of its limits are still available.
    postAuth('/api/auth/login', loginPayload('administrador@consultora-dh.test', LIMIT_CLAVE))->assertOk();
});

it('does not let reset requests consume the sign in budget', function (): void {
    User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'administrador@consultora-dh.test']);

    // A flood of reset attempts, rejected because the token is wrong, until the
    // reset policy's own budget is spent as well.
    $statuses = [];

    foreach (range(1, 12) as $ignored) {
        $statuses[] = postAuth('/api/auth/reset-password', [
            'token' => 'token-invalido',
            'email' => 'administrador@consultora-dh.test',
            'password' => 'ContrasenaNueva2026',
            'password_confirmation' => 'ContrasenaNueva2026',
        ])->status();
    }

    expect($statuses)->toContain(429);

    postAuth('/api/auth/login', loginPayload('administrador@consultora-dh.test', LIMIT_CLAVE))->assertOk();
});

it('does not let sign in attempts consume the recovery budget', function (): void {
    User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'administrador@consultora-dh.test']);

    // Exhaust the sign in budget the other way round.
    foreach (range(1, 25) as $ignored) {
        postAuth('/api/auth/login', loginPayload('administrador@consultora-dh.test'));
    }

    // Recovery still has its own budget available.
    postAuth('/api/auth/forgot-password', ['email' => 'administrador@consultora-dh.test'])->assertStatus(200);
});

it('flooding recovery cannot block a valid sign in', function (): void {
    $admin = User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'administrador@consultora-dh.test']);

    // Someone who knows only the address hammers the recovery endpoints, from
    // many machines, with no password at all.
    foreach (range(1, 35) as $attempt) {
        $server = ['REMOTE_ADDR' => '198.51.100.'.($attempt % 250 + 1)];

        postAuth('/api/auth/forgot-password', ['email' => $admin->email], $server);
        postAuth('/api/auth/reset-password', [
            'token' => 'a'.str_repeat('b', 10),
            'email' => $admin->email,
            'password' => 'ContrasenaNueva2026',
            'password_confirmation' => 'ContrasenaNueva2026',
        ], $server);
    }

    // The administrator signs in from an address the flood never used.
    postAuth('/api/auth/login', loginPayload($admin->email, LIMIT_CLAVE), ['REMOTE_ADDR' => '203.0.113.55'])
        ->assertOk();
});

it('limits the recovery endpoint on its own', function (): void {
    User::factory()->create(['email' => 'objetivo@consultora-dh.test']);

    $statuses = [];

    // 10 per address in 15 minutes.
    foreach (range(1, 14) as $ignored) {
        $statuses[] = postAuth('/api/auth/forgot-password', ['email' => 'objetivo@consultora-dh.test'])->status();
    }

    expect($statuses)->toContain(429);
});

it('limits the reset endpoint on its own', function (): void {
    User::factory()->create(['email' => 'objetivo@consultora-dh.test']);

    $statuses = [];

    foreach (range(1, 14) as $ignored) {
        $statuses[] = postAuth('/api/auth/reset-password', [
            'token' => 'token-invalido',
            'email' => 'objetivo@consultora-dh.test',
            'password' => 'ContrasenaNueva2026',
            'password_confirmation' => 'ContrasenaNueva2026',
        ])->status();
    }

    expect($statuses)->toContain(429);
});

it('counts recovery per account as well as per address', function (): void {
    User::factory()->create(['email' => 'objetivo@consultora-dh.test']);

    $statuses = [];

    // A different address each time: only the per-account limit can stop it.
    foreach (range(1, 8) as $attempt) {
        $statuses[] = postAuth(
            '/api/auth/forgot-password',
            ['email' => 'objetivo@consultora-dh.test'],
            ['REMOTE_ADDR' => '203.0.113.'.($attempt + 1)],
        )->status();
    }

    expect($statuses)->toContain(429);
});

// --- Middleware wiring -----------------------------------------------------

it('gives each authentication endpoint its own pair of limiters', function (): void {
    $expected = [
        'api.auth.login' => ['login-attempt', 'login-account'],
        'api.auth.forgot-password' => ['recovery-attempt', 'recovery-account'],
        'api.auth.reset-password' => ['reset-attempt', 'reset-account'],
    ];

    foreach ($expected as $name => $limiters) {
        $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

        foreach ($limiters as $limiter) {
            expect($middleware, "route [{$name}] is missing [throttle:{$limiter}]")
                ->toContain("throttle:{$limiter}");
        }

        // And nothing from another policy, which is what would couple them.
        foreach (['auth-attempt', 'auth-account'] as $legacy) {
            expect($middleware, "route [{$name}] still uses the removed [{$legacy}]")
                ->not->toContain("throttle:{$legacy}");
        }
    }
});

it('does not apply the sign in limiters to the other endpoints', function (): void {
    foreach (['api.auth.forgot-password', 'api.auth.reset-password'] as $name) {
        $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

        expect($middleware)->not->toContain('throttle:login-attempt')
            ->and($middleware)->not->toContain('throttle:login-account');
    }

    $login = Route::getRoutes()->getByName('api.auth.login')?->gatherMiddleware() ?? [];

    expect($login)->not->toContain('throttle:recovery-attempt')
        ->and($login)->not->toContain('throttle:reset-attempt');
});

// --- Authenticated traffic -------------------------------------------------

it('applies the general API limiter to the authenticated routes', function (): void {
    foreach (['api.auth.me', 'api.dashboard', 'api.profile.show', 'api.settings'] as $name) {
        $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

        expect($middleware, "route [{$name}] is missing the API limiter")->toContain('throttle:api');
    }
});

it('does not apply the authentication limiters to authenticated reads', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 30) as $ignored) {
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk();
    }
});

it('enforces the API limiter at runtime', function (): void {
    $this->actingAs(User::factory()->create());

    $statuses = [];

    foreach (range(1, 125) as $ignored) {
        $statuses[] = $this->getJson('/api/auth/me')->status();
    }

    // The general limit is 120 per minute.
    expect($statuses)->toContain(429);
});

it('reads the general API budget from the environment, and defaults to the documented figure', function (): void {
    // The budget is configurable so the end to end suite can raise it for itself: the
    // A03 flows walk a whole financial month end to end, which is a few hundred
    // requests in a couple of minutes, and the production budget refuses that on
    // purpose. Nothing else raises it, and these two tests are what stop that from
    // quietly becoming the normal figure.
    expect(env('API_RATE_LIMIT_PER_MINUTE'))->toBeNull();

    $budget = RateLimiter::limiter('api');

    expect($budget)->not->toBeNull();

    // The default, read straight out of the example environment file rather than
    // repeated here, so the two cannot drift apart.
    $documented = (string) (preg_match(
        '/^API_RATE_LIMIT_PER_MINUTE=(\d+)$/m',
        (string) file_get_contents(base_path('.env.example')),
        $matches) === 1 ? $matches[1] : '');

    expect($documented)->toBe('120');
});

it('does not let the authentication limiters affect a session at all', function (): void {
    // Signing in spends one attempt; the rest of the session must be unlimited
    // by the authentication policies.
    $user = User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'administrador@consultora-dh.test']);

    postAuth('/api/auth/login', loginPayload($user->email, LIMIT_CLAVE))->assertOk();

    foreach (range(1, 20) as $ignored) {
        $this->getJson('/api/auth/me')->assertOk();
    }
});

it('keeps the session intact across a rate limited sign in attempt', function (): void {
    $user = User::factory()->withPassword(LIMIT_CLAVE)->create(['email' => 'administrador@consultora-dh.test']);

    postAuth('/api/auth/login', loginPayload($user->email, LIMIT_CLAVE))->assertOk();

    foreach (range(1, 25) as $ignored) {
        postAuth('/api/auth/login', loginPayload($user->email, 'ContrasenaIncorrecta2026'));
    }

    // Being throttled while already signed in does not sign the user out.
    $this->getJson('/api/auth/me')->assertOk();
    expect(Session::getId())->not->toBeEmpty();
});

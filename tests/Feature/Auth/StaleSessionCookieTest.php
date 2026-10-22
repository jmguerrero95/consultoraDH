<?php

declare(strict_types=1);

use App\Domain\Users\UserStatus;
use App\Http\Middleware\PreserveSessionCookies;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| The session cookie race
|--------------------------------------------------------------------------
|
| Laravel re-sends the session cookie on every response that touched the
| session, with the id that request was using. The sign in regenerates the
| session — that is what defeats session fixation — so a request that was
| still in flight when the sign in completed answers afterwards with the OLD
| id, and the browser takes the old session back.
|
| The person sees "I signed in and it threw me back to the login screen", and
| the server's answer was a 200 every time. The A06 journey saw it as a portal
| client refused by the portal's own endpoint.
|
| ## These tests use the real pipeline
|
| An earlier version of this file invoked the middleware in isolation with a
| response prepared by hand, and it passed while the middleware could not do
| anything: it sat **inside** `StartSession`, which puts the cookie on the
| response *after* the middleware inside it have run. The cookie was not there
| to be removed.
|
| So every case here goes through the framework: the request leaves with the
| cookie the application itself issued, and the assertion is about the cookie
| the application itself sends back.
|
| ## The cookies are sent as the browser sends them
|
| The harness's `withCookie()` takes a value **in the clear** and encrypts it
| before the request goes out, because that is what the framework does with a
| cookie it is given by hand. A cookie that the application itself issued is
| already encrypted, so it has to go out untouched: that is
| `withUnencryptedCookie()`, which is exactly what a browser does.
|
*/

beforeEach(function (): void {
    // Nothing to prepare: every case here goes through the real framework.
});

/**
 * A cookie of a response, or null when the response does not carry it.
 */
function stale01Cookie(TestResponse $response, string $name): ?string
{
    foreach ($response->baseResponse->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return $cookie->getValue();
        }
    }

    return null;
}

/**
 * The session cookie of a response, or null.
 */
function stale01SessionCookie(TestResponse $response): ?string
{
    return stale01Cookie($response, (string) config('session.cookie'));
}

/**
 * The CSRF cookie of a response, or null.
 */
function stale01CsrfCookie(TestResponse $response): ?string
{
    return stale01Cookie($response, 'XSRF-TOKEN');
}

/**
 * The name of the session cookie.
 */
function stale01SessionCookieName(): string
{
    return (string) config('session.cookie');
}

/**
 * Gives the next request a clean session store and a clean guard.
 *
 * The framework hands the same session store and the same guard to every
 * request of a test, so what the previous request put in them would still be
 * there — and a request that is supposed to be anonymous would find the
 * previous request's account. A real request gets a fresh pair of both.
 */
function stale01ResetRequestState(): void
{
    app('session')->flush();
    app('auth')->forgetGuards();
}

/**
 * Returns to the pool the budget this test spends on purpose.
 *
 * The sign in limiter allows twenty attempts per address in five minutes and
 * the general API budget allows a hundred and twenty requests per minute.
 * This test performs twenty sign ins and more than a hundred API calls on
 * purpose, so it would spend more than the budget allows — and the limiter
 * would be right to refuse it. The limiter is not weakened for the test: what
 * the test spends is returned to the pool, one iteration at a time, so the
 * limiter keeps counting for everybody else and its own refusal is covered by
 * the tests that are about the limiter.
 */
function stale01RefundLimiter(string $email, ?int $userId = null): void
{
    $keys = [
        'login-attempt:login-ip|'.request()->ip(),
        'login-account:login-account|'.mb_strtolower(trim($email)),
        'api:api|'.request()->ip(),
    ];

    if ($userId !== null) {
        $keys[] = 'api:api|'.$userId;
    }

    foreach ($keys as $key) {
        RateLimiter::resetAttempts($key);
    }
}

it('runs outside the session middleware, where the response cookie already exists', function (): void {
    $groups = app(Kernel::class)->getMiddlewareGroups();
    $web = $groups['web'];

    $preserve = array_search(PreserveSessionCookies::class, $web, true);
    $session = array_search(StartSession::class, $web, true);

    expect($preserve)->toBeInt()
        ->and($session)->toBeInt();

    // Outside `StartSession`: that middleware puts the cookie on the response
    // *after* the middleware inside it have run, so a middleware inside it
    // would inspect a response that has no session cookie yet.
    expect($preserve)->toBeLessThan($session);

    // And it is the framework's own encryption, not a second one: the class
    // extends `EncryptCookies` and takes its place in the group, so the
    // framework still encrypts and decrypts exactly what it always did.
    expect(is_subclass_of(PreserveSessionCookies::class, EncryptCookies::class))->toBeTrue()
        ->and($web)->not->toContain(EncryptCookies::class);
});

it('does not repeat the session cookie the browser already holds', function (): void {
    // The first request establishes the session, and the browser keeps the
    // cookie the application issued.
    $first = $this->get('/login');

    $first->assertOk();

    $cookie = stale01SessionCookie($first);

    expect($cookie)->not->toBeNull();

    // The second request arrives with it. The session did not change, so the
    // response must not repeat the cookie: the browser already has it.
    $second = $this
        ->withUnencryptedCookie(stale01SessionCookieName(), $cookie)
        ->get('/login');

    $second->assertOk();

    expect(stale01SessionCookie($second))->toBeNull();
});

it('does not repeat the CSRF cookie the browser already holds', function (): void {
    // The CSRF token lives inside the session, so the two have to move
    // together: a browser holding the new session with the old token would be
    // refused with a 419 on its next write.
    $first = $this->get('/login');

    $first->assertOk();

    $session = stale01SessionCookie($first);
    $csrf = stale01CsrfCookie($first);

    expect($session)->not->toBeNull()
        ->and($csrf)->not->toBeNull();

    $second = $this
        ->withUnencryptedCookie(stale01SessionCookieName(), $session)
        ->withUnencryptedCookie('XSRF-TOKEN', $csrf)
        ->get('/login');

    $second->assertOk();

    expect(stale01CsrfCookie($second))->toBeNull();
});

it('still hands the browser a new session when the sign in rotates it', function (): void {
    User::factory()->withPassword('ContrasenaStale2026')->create([
        'email' => 'rotacion@consultora-dh.test',
    ]);

    $first = $this->get('/login');

    $first->assertOk();

    $before = stale01SessionCookie($first);

    expect($before)->not->toBeNull();

    $login = $this
        ->withCredentials()
        ->withUnencryptedCookie(stale01SessionCookieName(), $before)
        ->postJson('/api/auth/login', [
            'email' => 'rotacion@consultora-dh.test',
            'password' => 'ContrasenaStale2026',
        ]);

    $login->assertOk();

    $after = stale01SessionCookie($login);

    // The session really did rotate, and the browser is told about it.
    expect($after)->not->toBeNull()
        ->and($after)->not->toBe($before);
});

it('does not weaken the session of an account that is signed in', function (): void {
    User::factory()->withPassword('ContrasenaStale2026')->create([
        'email' => 'vivo@consultora-dh.test',
        'status' => UserStatus::Active,
    ]);

    $login = $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'vivo@consultora-dh.test',
            'password' => 'ContrasenaStale2026',
        ]);

    $login->assertOk();

    // The session of a signed in account is still usable afterwards.
    $this->get('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'vivo@consultora-dh.test');
});

it('still refuses an inactive account', function (): void {
    User::factory()->withPassword('ContrasenaStale2026')->create([
        'email' => 'inactivo-stale@consultora-dh.test',
        'status' => UserStatus::Inactive,
    ]);

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'inactivo-stale@consultora-dh.test',
            'password' => 'ContrasenaStale2026',
        ])
        ->assertStatus(422);

    expect(DB::table('users')
        ->where('email', 'inactivo-stale@consultora-dh.test')
        ->value('last_login_at'))->toBeNull();
});

it('sends the session cookie encrypted and unreadable from the outside', function (): void {
    $response = $this->get('/login');

    $response->assertOk();

    $cookie = stale01SessionCookie($response);

    expect($cookie)->not->toBeNull();

    // The value on the wire is the framework's own encrypted envelope. It
    // decrypts to the session id, and the session id is nowhere in the clear.
    $decrypted = app('encrypter')->decrypt($cookie, false);
    $sessionId = CookieValuePrefix::remove($decrypted);

    expect($sessionId)->toBe($this->app['session']->getId())
        ->and($cookie)->not->toContain($sessionId);

    // And the browser cannot read it from JavaScript.
    foreach ($response->baseResponse->headers->getCookies() as $sent) {
        if ($sent->getName() === stale01SessionCookieName()) {
            expect($sent->isHttpOnly())->toBeTrue();
        }
    }
});

it('does not let a stale response take the new session back', function (): void {
    $email = 'carrera@consultora-dh.test';

    $user = User::factory()->withPassword('ContrasenaStale2026')->create([
        'email' => $email,
        'status' => UserStatus::Active,
    ]);

    $cookieName = stale01SessionCookieName();

    // The browser's first request. It has no session, so the application gives
    // it one: this is the anonymous session that the sign in will replace.
    $establish = $this->get('/login');

    $establish->assertOk();

    $anonymous = stale01SessionCookie($establish);

    expect($anonymous)->not->toBeNull();

    // Twenty repetitions of the same race, with the order of the responses
    // under the test's control: the sign in's answer reaches the browser
    // first, and the request that was already in flight answers afterwards.
    //
    // Without the correction the browser takes the anonymous session back on
    // every single one of them, and the person is signed out again.
    for ($iteration = 1; $iteration <= 20; $iteration++) {
        stale01RefundLimiter($email, $user->id);

        stale01ResetRequestState();

        // 1. The sign in regenerates the session: it replaces the anonymous
        //    one the browser was holding.
        $login = $this
            ->withCredentials()
            ->withUnencryptedCookie($cookieName, $anonymous)
            ->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'ContrasenaStale2026',
            ]);

        $login->assertOk();

        $rotated = stale01SessionCookie($login);

        expect($rotated)->not->toBeNull()
            ->and($rotated)->not->toBe($anonymous);

        stale01ResetRequestState();

        // 2. A request that was dispatched before the sign in answers after
        //    it. It carries the anonymous session, so its answer repeats it —
        //    and the browser would take the anonymous session back, which is
        //    the sign out nobody asked for.
        $stale = $this
            ->withUnencryptedCookie($cookieName, $anonymous)
            ->get('/api/auth/me');

        $stale->assertStatus(401);

        expect(stale01SessionCookie($stale), "iteration {$iteration}")->toBeNull();

        stale01ResetRequestState();

        // 3. The browser, which took the new session from the sign in, is
        //    still signed in.
        $me = $this
            ->withUnencryptedCookie($cookieName, $rotated)
            ->get('/api/auth/me');

        $me->assertOk()
            ->assertJsonPath('user.email', $email);
    }
});

it('hands the browser a new session when the account changes', function (): void {
    $first = User::factory()->withPassword('ContrasenaStale2026')->create([
        'email' => 'cambio-uno@consultora-dh.test',
        'status' => UserStatus::Active,
    ]);

    $second = User::factory()->withPassword('ContrasenaStale2026')->create([
        'email' => 'cambio-dos@consultora-dh.test',
        'status' => UserStatus::Active,
    ]);

    $cookieName = stale01SessionCookieName();

    stale01RefundLimiter($first->email, $first->id);
    stale01RefundLimiter($second->email, $second->id);

    // The first account signs in.
    $loginOne = $this
        ->withCredentials()
        ->postJson('/api/auth/login', [
            'email' => $first->email,
            'password' => 'ContrasenaStale2026',
        ]);

    $loginOne->assertOk();

    $sessionOne = stale01SessionCookie($loginOne);

    expect($sessionOne)->not->toBeNull();

    // The second account signs in on the same browser. The session is
    // regenerated, so the browser is given a different one.
    $loginTwo = $this
        ->withCredentials()
        ->withUnencryptedCookie($cookieName, $sessionOne)
        ->postJson('/api/auth/login', [
            'email' => $second->email,
            'password' => 'ContrasenaStale2026',
        ]);

    $loginTwo->assertOk();

    $sessionTwo = stale01SessionCookie($loginTwo);

    expect($sessionTwo)->not->toBeNull()
        ->and($sessionTwo)->not->toBe($sessionOne);

    // The browser is the second account.
    $me = $this
        ->withUnencryptedCookie($cookieName, $sessionTwo)
        ->get('/api/auth/me');

    $me->assertOk()
        ->assertJsonPath('user.email', $second->email);

    // A request that was dispatched before the change answers after it: it
    // must not put the first account's session back.
    $stale = $this
        ->withUnencryptedCookie($cookieName, $sessionOne)
        ->get('/api/auth/me');

    expect(stale01SessionCookie($stale))->toBeNull();

    // And the browser is still the second account.
    $this
        ->withUnencryptedCookie($cookieName, $sessionTwo)
        ->get('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', $second->email);
});

it('hands the browser a new session when the person signs out', function (): void {
    $user = User::factory()->withPassword('ContrasenaStale2026')->create([
        'email' => 'salir@consultora-dh.test',
        'status' => UserStatus::Active,
    ]);

    $cookieName = stale01SessionCookieName();

    stale01RefundLimiter($user->email, $user->id);

    $login = $this
        ->withCredentials()
        ->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'ContrasenaStale2026',
        ]);

    $login->assertOk();

    $session = stale01SessionCookie($login);

    expect($session)->not->toBeNull();

    $logout = $this
        ->withCredentials()
        ->withUnencryptedCookie($cookieName, $session)
        ->postJson('/api/auth/logout');

    $logout->assertOk();

    $rotated = stale01SessionCookie($logout);

    // The sign out invalidates the session and gives a new one, so the browser
    // is told about it.
    expect($rotated)->not->toBeNull()
        ->and($rotated)->not->toBe($session);

    // The old session does not work any more.
    $this
        ->withUnencryptedCookie($cookieName, $session)
        ->get('/api/auth/me')
        ->assertStatus(401);

    // And the new one is not signed in either.
    $this
        ->withUnencryptedCookie($cookieName, $rotated)
        ->get('/api/auth/me')
        ->assertStatus(401);
});

it('accepts a write with a valid CSRF token and refuses one without', function (): void {
    // The framework's own CSRF middleware skips the check while the suite
    // runs, so the environment is switched for this test alone: the refusal
    // has to be proved against the real middleware, not against a disabled
    // one. Nothing else reads the environment binding.
    $this->app->instance('env', 'e2e');

    $user = User::factory()->withPassword('ContrasenaStale2026')->create([
        'email' => 'csrf@consultora-dh.test',
        'status' => UserStatus::Active,
    ]);

    stale01RefundLimiter($user->email, $user->id);

    // Without a token the write is refused, and the refusal is the framework's.
    $this
        ->withSession(['_token' => 'token-de-prueba'])
        ->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'ContrasenaStale2026',
        ])
        ->assertStatus(419)
        ->assertJsonPath('code', 'session_expired');

    // With the token the write goes through.
    $this
        ->withSession(['_token' => 'token-de-prueba'])
        ->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'ContrasenaStale2026',
        ], ['X-CSRF-TOKEN' => 'token-de-prueba'])
        ->assertOk()
        ->assertJsonPath('user.email', $user->email);
});

it('refuses the write of an account that lost its permission', function (): void {
    // The role is not touched by the session's own machinery: an account
    // without the permission is still refused, and one with it is not.
    $user = User::factory()->withPassword('ContrasenaStale2026')->create([
        'email' => 'permiso@consultora-dh.test',
        'status' => UserStatus::Active,
    ]);

    stale01RefundLimiter($user->email, $user->id);

    $login = $this
        ->withCredentials()
        ->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'ContrasenaStale2026',
        ]);

    $login->assertOk();

    $session = stale01SessionCookie($login);

    // The settings screen needs a permission this account does not have.
    $this
        ->withUnencryptedCookie(stale01SessionCookieName(), $session)
        ->get('/api/settings')
        ->assertStatus(403);
});

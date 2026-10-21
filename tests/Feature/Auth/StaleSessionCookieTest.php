<?php

declare(strict_types=1);

use App\Domain\Users\UserStatus;
use App\Http\Middleware\PreserveSessionCookies;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\NullSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/*
|--------------------------------------------------------------------------
| A01 — a stale response must not take a session away
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
| The invariant is the one the fix implements: **a response that repeats the
| very cookie the browser already holds does not carry it.** The browser
| already has that value; sending it again changes nothing, except when the
| browser has moved on — which is the race — and then it undoes what happened
| in between.
|
*/

beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * A request that arrives with a session cookie of a given value.
 */
function stale01Request(string $name, string $value): Request
{
    $request = Request::create('/api/auth/me');

    $session = new Store($name, new NullSessionHandler);
    $session->setId($value);

    $request->setLaravelSession($session);
    $request->cookies->set($name, $value);

    return $request;
}

/**
 * A response that carries a session cookie of a given value.
 */
function stale01Response(string $name, string $value): Response
{
    $response = new Response;
    $response->headers->setCookie(new Cookie($name, $value, 3600, '/', null, false, true, false, 'lax'));

    return $response;
}

it('does not repeat the session cookie the browser already holds', function (): void {
    $name = 'consultora_dh_e2e_session';

    $response = stale01Response($name, 'the-old-session');

    $handled = (new PreserveSessionCookies)->handle(
        stale01Request($name, 'the-old-session'),
        fn (): Response => $response,
    );

    expect($handled->headers->getCookies())->toBeEmpty();
});

it('does not repeat the CSRF cookie the browser already holds', function (): void {
    // The CSRF token lives inside the session, so the two have to move
    // together: a browser holding the new session with the old token would be
    // refused with a 419 on its next write.
    $name = 'XSRF-TOKEN';

    $response = stale01Response($name, 'the-old-token');

    $handled = (new PreserveSessionCookies)->handle(
        stale01Request($name, 'the-old-token'),
        fn (): Response => $response,
    );

    expect($handled->headers->getCookies())->toBeEmpty();
});

it('keeps a cookie that is not the one the request arrived with', function (): void {
    // This is the sign in: the session id changed, so the browser has to be
    // told. Stripping it here would leave the browser without a session.
    $name = 'consultora_dh_e2e_session';

    $response = stale01Response($name, 'the-new-session');

    $handled = (new PreserveSessionCookies)->handle(
        stale01Request($name, 'the-old-session'),
        fn (): Response => $response,
    );

    $cookies = $handled->headers->getCookies();

    expect($cookies)->toHaveCount(1)
        ->and($cookies[0]->getValue())->toBe('the-new-session');
});

it('keeps the cookies of a response that touches nothing of the session', function (): void {
    $name = 'consultora_dh_e2e_session';

    $response = new Response;
    $response->headers->setCookie(new Cookie('laravel_session_think', 'something', 3600, '/', null, false, true, false, 'lax'));

    $handled = (new PreserveSessionCookies)->handle(
        stale01Request($name, 'the-old-session'),
        fn (): Response => $response,
    );

    expect($handled->headers->getCookies())->toHaveCount(1);
});

it('still rotates the session on sign in', function (): void {
    User::factory()->withPassword('ContrasenaStale2026')->create([
        'email' => 'rotacion@consultora-dh.test',
    ]);

    $this->withSession(['_token' => 'test-csrf-token'])->get('/login');

    $before = $this->app['session']->getId();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'rotacion@consultora-dh.test',
            'password' => 'ContrasenaStale2026',
        ])
        ->assertOk();

    expect($this->app['session']->getId())->not->toBe($before);
});

it('does not weaken the session of an account that is signed in', function (): void {
    User::factory()->withPassword('ContrasenaStale2026')->create([
        'email' => 'vivo@consultora-dh.test',
        'status' => UserStatus::Active,
    ]);

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'vivo@consultora-dh.test',
            'password' => 'ContrasenaStale2026',
        ])
        ->assertOk();

    // The session of a signed in account is still usable afterwards.
    $this->get('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'vivo@consultora-dh.test');
});

it('still refuses an inactive account', function (): void {
    User::factory()->inactive()->withPassword('ContrasenaStale2026')->create([
        'email' => 'inactivo-stale@consultora-dh.test',
    ]);

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => 'inactivo-stale@consultora-dh.test',
            'password' => 'ContrasenaStale2026',
        ])
        ->assertStatus(422);

    expect(DB::table('users')->where('email', 'inactivo-stale@consultora-dh.test')->value('last_login_at'))->toBeNull();
});

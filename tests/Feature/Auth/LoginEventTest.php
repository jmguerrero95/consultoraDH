<?php

declare(strict_types=1);

use App\Domain\Auth\Events\AuthenticationSucceeded;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;

const LOGIN_PASSWORD = 'ContrasenaDeInicio2026';

it('emits the framework Login event exactly once for a successful sign in', function (): void {
    $framework = 0;
    $domain = 0;

    Event::listen(Login::class, function () use (&$framework): void {
        $framework += 1;
    });

    Event::listen(AuthenticationSucceeded::class, function () use (&$domain): void {
        $domain += 1;
    });

    $user = User::factory()->withPassword(LOGIN_PASSWORD)->create();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => LOGIN_PASSWORD,
        ])
        ->assertOk();

    // `Auth::login()` already dispatches it. Dispatching it again would make
    // every listener run twice for a single sign in.
    expect($framework)->toBe(1)
        ->and($domain)->toBe(1);
});

it('emits no Login event when the attempt is rejected', function (): void {
    $framework = 0;

    Event::listen(Login::class, function () use (&$framework): void {
        $framework += 1;
    });

    $user = User::factory()->withPassword(LOGIN_PASSWORD)->create();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'ContrasenaIncorrecta2026',
        ])
        ->assertStatus(422);

    expect($framework)->toBe(0);
});

it('emits no Login event for an inactive account', function (): void {
    $framework = 0;

    Event::listen(Login::class, function () use (&$framework): void {
        $framework += 1;
    });

    $user = User::factory()->inactive()->withPassword(LOGIN_PASSWORD)->create();

    $this->withSession(['_token' => 'test-csrf-token'])
        ->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => LOGIN_PASSWORD,
        ])
        ->assertStatus(422);

    expect($framework)->toBe(0);
});

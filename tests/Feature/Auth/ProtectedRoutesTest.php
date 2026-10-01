<?php

declare(strict_types=1);

use App\Models\User;

it('refuses a protected endpoint to a guest', function (): void {
    $this->getJson('/api/dashboard')
        ->assertStatus(401)
        ->assertJsonPath('code', 'unauthenticated');
});

it('refuses the profile endpoint to a guest', function (): void {
    $this->getJson('/api/profile')->assertStatus(401);
});

it('refuses the settings endpoint to a guest', function (): void {
    $this->getJson('/api/settings')->assertStatus(401);
});

it('lets an authenticated user read the protected endpoints', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', $user->email);

    $this->actingAs($user)->getJson('/api/dashboard')->assertOk();
    $this->actingAs($user)->getJson('/api/profile')->assertOk();
});

it('serves the application shell to a guest', function (): void {
    // The document itself carries no user data, so it is public. The interface
    // then asks the API who the user is.
    $this->get('/login')
        ->assertOk()
        ->assertSee('Consultora DH', escape: false)
        ->assertSee('app-version', escape: false);
});

it('serves the shell for a deep link so the router can resolve it', function (): void {
    $this->get('/profile')->assertOk()->assertSee('<div id="app">', escape: false);
});

it('answers an unknown API path with a JSON 404', function (): void {
    $this->getJson('/api/ruta-inexistente')
        ->assertStatus(404)
        ->assertJsonPath('code', 'not_found');
});

it('serves the shell for an unknown document path', function (): void {
    // The interface renders its own 404 screen.
    $this->get('/pagina-inexistente')->assertOk();
});

it('never exposes the session cookie to scripts', function (): void {
    $response = $this->get('/login');

    $cookie = $response->getCookie((string) config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie?->isHttpOnly())->toBeTrue();
});

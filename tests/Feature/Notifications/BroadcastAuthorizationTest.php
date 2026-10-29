<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\User;

/**
 * PERF-01-R1 — `/broadcasting/auth` keeps the real channel authorization.
 *
 * Realtime now takes the CSRF value from the `XSRF-TOKEN` cookie and sends it in
 * `X-XSRF-TOKEN` (the header Laravel decrypts with the same key as that cookie).
 * Removing the `<meta name="csrf-token">` must not turn into weakening CSRF, so
 * this test drives the endpoint the browser drives: an authenticated session
 * gets a signature for a channel it may see, and everyone else is refused by the
 * channel callback itself.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    // The suite forces BROADCAST_CONNECTION=null so no test talks to Reverb.
    // The authorization endpoint is still the real Pusher-protocol one; point
    // it at the configured connection so the channel callbacks actually run.
    config(['broadcasting.default' => 'reverb']);

    // Channel callbacks are registered at boot on whichever connection was the
    // default then (the suite forces `null`). Register the real file on the
    // connection this test now points at, so the callbacks under test are the
    // ones the endpoint invokes.
    require base_path('routes/channels.php');
});

it('signs a private channel for a staff member who can see the conversation', function (): void {
    $staff = userWithPermissions(['support.view_all']);
    $conversation = SupportConversation::factory()->create();

    $response = $this->actingAs($staff)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-support.conversation.'.$conversation->id,
    ]);

    $response->assertOk()->assertJsonStructure(['auth']);

    expect((string) $response->json('auth'))->toContain(':');
});

it('refuses a staff member without access to the conversation', function (): void {
    $staff = userWithPermissions(['tasks.view']);
    $conversation = SupportConversation::factory()->create();

    $this->actingAs($staff)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-support.conversation.'.$conversation->id,
    ])->assertForbidden();
});

it('refuses a client account that does not own the conversation', function (): void {
    $client = Client::factory()->create();
    $conversation = SupportConversation::factory()->create([
        'client_id' => Client::factory()->create()->id,
    ]);

    $user = User::factory()->create([
        'account_type' => 'client',
        'client_id' => $client->id,
    ]);

    $this->actingAs($user)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-support.conversation.'.$conversation->id,
    ])->assertForbidden();
});

it('refuses a guest and an unparsable conversation id without a server error', function (): void {
    $this->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-support.conversation.1',
    ])->assertForbidden();

    $staff = userWithPermissions(['support.view_all']);

    $this->actingAs($staff)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-support.conversation.NaN',
    ])->assertForbidden();
});

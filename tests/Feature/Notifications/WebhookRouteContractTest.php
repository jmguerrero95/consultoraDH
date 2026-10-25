<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/**
 * The webhook's own contract, asserted independently of the flow tests.
 *
 * Two things had been declared in comments and were not true in the routes:
 * the webhook lived inside the authenticated group, so Telegram's push was
 * answered 401 before the controller ran; and it was not in the CSRF
 * except list, so it would have been refused 419 in production. Tests never
 * exercise CSRF (the framework skips it while running the suite), so the
 * exception is asserted on the framework's own list rather than left to a
 * claim in a comment.
 */
it('publishes the Telegram webhook outside the authenticated group', function (): void {
    $webhook = Route::getRoutes()->getByName('api.telegram.webhook');

    expect($webhook)->not->toBeNull();

    $middleware = $webhook->gatherMiddleware();

    expect($middleware)->not->toContain('auth')
        ->and($middleware)->not->toContain('auth.session')
        ->and($middleware)->not->toContain('user.active');
});

it('keeps the authenticated Telegram endpoints authenticated', function (): void {
    $binding = Route::getRoutes()->getByName('api.telegram.binding-token');
    $endpoints = Route::getRoutes()->getByName('api.telegram.endpoints.index');

    expect($binding->gatherMiddleware())->toContain('auth')
        ->and($endpoints->gatherMiddleware())->toContain('auth');
});

it('excludes the webhook and the push actions from CSRF verification', function (): void {
    $excluded = app(PreventRequestForgery::class)->getExcludedPaths();

    expect($excluded)->toContain('api/telegram/webhook')
        ->and($excluded)->toContain('api/push/reminders/*/ack')
        ->and($excluded)->toContain('api/push/reminders/*/dismiss');
});

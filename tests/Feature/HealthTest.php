<?php

declare(strict_types=1);

it('reports the health of the application and its dependencies', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', true)
        ->assertJsonPath('checks.redis', true);
});

it('reveals nothing beyond a status and up/down state', function (): void {
    $body = $this->getJson('/api/health')->assertOk()->getContent();

    // No software versions, no host or container names, no infrastructure detail.
    foreach ([
        'version',
        'application',
        'PostgreSQL',
        'pg_',
        'Redis',
        'redis_version',
        'nginx',
        'localhost',
        config('database.connections.testing.database'),
        (string) config('database.connections.testing.password'),
        (string) config('app.key'),
    ] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }

    // The exact set of keys is the contract.
    expect(array_keys(json_decode($body, true) ?: []))->toBe(['status', 'checks']);
    expect(array_keys((array) (json_decode($body, true)['checks'] ?? [])))->toBe(['database', 'redis']);
});

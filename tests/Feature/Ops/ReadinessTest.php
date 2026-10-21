<?php

declare(strict_types=1);

use App\Services\Ops\QueueInspector;
use Illuminate\Support\Facades\Queue;

/**
 * G11: liveness and readiness are two different questions.
 *
 * nginx's own `/healthz` answers "is this process alive?", and it must stay
 * cheap: a container that is merely slow to boot must not be restarted for
 * it. A release gate asks something else — "can this deployment take work
 * and finish it?" — and that question needs the database, Redis and the
 * queues, including the age of the oldest job still waiting.
 *
 * A deployment whose workers are all dead passes a liveness probe and fails
 * the readiness one. That difference is the point.
 */
it('answers the liveness probe as long as the application responds', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', true)
        ->assertJsonPath('checks.redis', true);
});

it('reports readiness with the state of every monitored queue', function (): void {
    $this->getJson('/api/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('checks.database', true)
        ->assertJsonPath('checks.redis', true)
        ->assertJsonPath('checks.queues', true)
        ->assertJsonStructure([
            'queues' => ['default'],
            'thresholds' => ['max_backlog', 'max_age_seconds', 'max_failed'],
        ]);
});

it('is not ready when the oldest job has been waiting longer than the threshold', function (): void {
    config([
        'queue.monitoring.queues' => ['readiness-probe'],
        'queue.monitoring.max_age_seconds' => 30,
        'queue.monitoring.max_backlog' => 1000,
    ]);

    $queue = 'readiness-probe';
    $connection = Queue::connection('redis');
    $payload = json_encode([
        'displayName' => 'Probe',
        'job' => 'Probe',
        'maxTries' => 1,
        'createdAt' => now()->subSeconds(600)->getTimestamp(),
    ]);

    $connection->getConnection()->rpush($connection->getQueue($queue), $payload);

    try {
        $response = $this->getJson('/api/ready');

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'not_ready');
        $response->assertJsonPath('checks.queues', false);
        $response->assertJsonPath('queues.'.$queue.'.over_age', true);
    } finally {
        $connection->getConnection()->del($connection->getQueue($queue));
    }
});

it('reads the same queue state the health command reports', function (): void {
    $inspector = new QueueInspector('redis');

    $queue = 'shared-state-probe';
    $connection = Queue::connection('redis');
    $connection->getConnection()->rpush($connection->getQueue($queue), json_encode([
        'displayName' => 'Probe',
        'job' => 'Probe',
        'maxTries' => 1,
        'createdAt' => now()->subSeconds(42)->getTimestamp(),
    ]));

    try {
        expect($inspector->pendingSize($queue))->toBe(1)
            ->and($inspector->oldestPendingSeconds($queue))->toBeGreaterThanOrEqual(40)
            ->and($inspector->oldestPendingSeconds($queue))->toBeLessThanOrEqual(45);
    } finally {
        $connection->getConnection()->del($connection->getQueue($queue));
    }
});

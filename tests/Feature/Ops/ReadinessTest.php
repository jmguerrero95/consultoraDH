<?php

declare(strict_types=1);

use App\Services\Ops\QueueInspector;
use Illuminate\Support\Facades\Queue;

/**
 * G11 and A01-OPS-04: liveness and readiness are two different questions, and
 * the readiness answer is for the load balancer, not for the curious.
 *
 * nginx's own `/healthz` answers "is this process alive?", and it must stay
 * cheap: a container that is merely slow to boot must not be restarted for
 * it. A release gate asks something else — "can this deployment take work
 * and finish it?" — and that question needs the database, Redis and the
 * queues, including the age of the oldest job still waiting.
 *
 * A deployment whose workers are all dead passes a liveness probe and fails
 * the readiness one. That difference is the point.
 *
 * The detail behind that answer — how deep each queue is, how old its oldest
 * job is, how many jobs have failed — is operational metric, and it belongs
 * to an administrator and not to whoever asks the public endpoint.
 */
it('answers the liveness probe as long as the application responds', function (): void {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database', true)
        ->assertJsonPath('checks.redis', true);
});

it('answers the readiness probe with the state of every monitored dependency', function (): void {
    $this->getJson('/api/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('checks.database', true)
        ->assertJsonPath('checks.redis', true)
        ->assertJsonPath('checks.queues', true);
});

it('does not publish the internal metrics of the deployment to a visitor', function (): void {
    $response = $this->getJson('/api/ready');

    $response->assertOk();

    // The size of a queue, the age of its oldest job, the failed jobs and the
    // thresholds they are judged against are the inside of the deployment.
    expect($response->json())->not->toHaveKey('queues')
        ->and($response->json())->not->toHaveKey('failed_jobs')
        ->and($response->json())->not->toHaveKey('thresholds')
        ->and(json_encode($response->json()))->not->toContain('oldest_pending_seconds')
        ->and(json_encode($response->json()))->not->toContain('max_backlog');
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
    } finally {
        $connection->getConnection()->del($connection->getQueue($queue));
    }
});

it('shows the operational detail to an administrator', function (): void {
    seedPortfolioRoles();

    $admin = userWithPermissions(['settings.view']);

    $this->actingAs($admin)
        ->getJson('/api/settings/readiness')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonStructure([
            'queues' => ['default'],
            'failed_jobs',
            'thresholds' => ['max_backlog', 'max_age_seconds', 'max_failed'],
        ]);
});

it('does not show the operational detail to a user without the permission', function (): void {
    seedPortfolioRoles();

    $reader = userWithPermissions(['clients.view']);

    $this->actingAs($reader)
        ->getJson('/api/settings/readiness')
        ->assertForbidden();

    // And to a visitor: the route is inside the authenticated group and the
    // authorization is refused before anything is read.
    $this->getJson('/api/settings/readiness')
        ->assertForbidden();
});

it('reports the queue that is over its age to an administrator', function (): void {
    seedPortfolioRoles();

    config([
        'queue.monitoring.queues' => ['readiness-probe'],
        'queue.monitoring.max_age_seconds' => 30,
        'queue.monitoring.max_backlog' => 1000,
    ]);

    $queue = 'readiness-probe';
    $connection = Queue::connection('redis');
    $connection->getConnection()->rpush($connection->getQueue($queue), json_encode([
        'displayName' => 'Probe',
        'job' => 'Probe',
        'maxTries' => 1,
        'createdAt' => now()->subSeconds(600)->getTimestamp(),
    ]));

    try {
        $admin = userWithPermissions(['settings.view']);

        $this->actingAs($admin)
            ->getJson('/api/settings/readiness')
            ->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('queues.'.$queue.'.over_age', true);
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

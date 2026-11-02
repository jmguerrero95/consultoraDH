<?php

declare(strict_types=1);

use App\Listeners\RecordWorkerHeartbeat;
use App\Services\Ops\QueueAssessment;
use App\Services\Ops\QueueInspector;
use App\Services\Ops\WorkerHealth;
use App\Services\Ops\WorkerHeartbeat;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleased;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * OBS-01: a worker is alive only when its own process says so.
 *
 * The queue depth cannot answer that question: an empty queue looks the same
 * with a healthy worker and with no worker at all. These tests cover the
 * evidence that does answer it, and the cases where the evidence is missing or
 * the inspection itself failed.
 *
 * They also pin the difference between a central dependency and a secondary
 * capability: an AI backlog degrades AI, not the administrative platform.
 */

/**
 * A real job that runs through the queue, so the heartbeat listener is
 * exercised by the framework rather than called by hand.
 */
final class Obs01ProbeJob implements ShouldQueue
{
    use Dispatchable;

    public static int $runs = 0;

    public function handle(): void
    {
        self::$runs++;
    }
}

beforeEach(function (): void {
    // The array cache is the test cache store; the heartbeat writes there.
    // The worker group comes from the process environment, not from config,
    // so no test may inherit one by accident.
    putenv('QUEUE_WORKER_GROUP');
});

afterEach(function (): void {
    putenv('QUEUE_WORKER_GROUP');
});

it('reports every healthy queue as verified and an empty queue as a measurement', function (): void {
    config([
        'queue.monitoring.queues' => ['default', 'ai-tasks'],
        'queue.monitoring.max_failed' => PHP_INT_MAX,
    ]);

    $assessment = (new QueueAssessment(new QueueInspector('redis')))->inspect();

    expect($assessment['state'])->toBe(QueueAssessment::STATE_VERIFIED)
        ->and($assessment['queues']['default']['state'])->toBe(QueueAssessment::STATE_VERIFIED)
        ->and($assessment['queues']['default']['oldest_status'])->toBe('empty')
        ->and($assessment['queues']['ai-tasks']['state'])->toBe(QueueAssessment::STATE_VERIFIED)
        ->and($assessment['capabilities']['operations']['state'])->toBe(QueueAssessment::STATE_VERIFIED);
});

it('never reports a queue whose inspection failed as verified', function (): void {
    config([
        'queue.monitoring.queues' => ['probe'],
        'queue.connections.broken' => [
            'driver' => 'redis',
            'connection' => 'missing-redis-connection',
            'queue' => 'default',
            'retry_after' => 900,
        ],
    ]);

    $assessment = (new QueueAssessment(new QueueInspector('broken')))->inspect();

    expect($assessment['state'])->toBe(QueueAssessment::STATE_DEGRADED)
        ->and($assessment['queues']['probe']['state'])->toBe(QueueAssessment::STATE_DEGRADED)
        ->and($assessment['queues']['probe']['oldest_status'])->toBe('unavailable')
        ->and($assessment['queues']['probe']['error'])->not->toBeNull();
});

it('reports the queue failure on the system status screen instead of a false verified', function (): void {
    seedPortfolioRoles();

    config([
        'queue.default' => 'broken',
        'queue.monitoring.queues' => ['probe'],
        'queue.connections.broken' => [
            'driver' => 'redis',
            'connection' => 'missing-redis-connection',
            'queue' => 'default',
            'retry_after' => 900,
        ],
    ]);

    $admin = userWithPermissions(['settings.view']);

    $this->actingAs($admin)
        ->getJson('/api/settings/status')
        ->assertOk()
        ->assertJsonPath('services.queue.state', QueueAssessment::STATE_DEGRADED)
        ->assertJsonPath('services.queue.queues.0.name', 'probe')
        ->assertJsonPath('services.queue.queues.0.oldest_status', 'unavailable')
        // Without a heartbeat, a worker group is unknown — never `connected`.
        ->assertJsonPath('services.workers.state', QueueAssessment::STATE_UNKNOWN)
        ->assertJsonCount(4, 'services.workers.groups')
        ->assertJsonPath('services.workers.groups.0.group', 'interactive')
        ->assertJsonPath('services.workers.groups.0.activity', 'unknown');
});

it('reports a group without a heartbeat as unknown, never as connected', function (): void {
    $groups = (new WorkerHealth(new WorkerHeartbeat))->inspect();

    expect($groups)->not->toBeEmpty();

    foreach ($groups as $group) {
        expect($group['state'])->toBe(QueueAssessment::STATE_UNKNOWN)
            ->and($group['activity'])->toBe('unknown')
            ->and($group['state'])->not->toBe('connected')
            ->and($group['state'])->not->toBe(QueueAssessment::STATE_VERIFIED);
    }
});

it('reports an idle worker with a recent heartbeat as healthy', function (): void {
    $heartbeat = new WorkerHeartbeat('interactive');
    $heartbeat->recordIdle();

    $group = (new WorkerHealth($heartbeat))->inspect()['interactive'];

    expect($group['state'])->toBe(QueueAssessment::STATE_VERIFIED)
        ->and($group['activity'])->toBe('idle')
        ->and($group['age_seconds'])->toBeLessThanOrEqual(1);
});

it('does not declare a worker dead while it runs a job inside its own timeout', function (): void {
    $heartbeat = new WorkerHeartbeat('imports');
    $heartbeat->recordBusy('App\\Jobs\\ApplyLegacyImport');

    // Imports are allowed 630 s plus a 60 s margin.
    $this->travel(300)->seconds();

    $group = (new WorkerHealth($heartbeat))->inspect()['imports'];

    expect($group['state'])->toBe(QueueAssessment::STATE_VERIFIED)
        ->and($group['activity'])->toBe('busy')
        ->and($group['job'])->toBe('ApplyLegacyImport')
        ->and($group['job_age_seconds'])->toBeGreaterThanOrEqual(300);
});

it('reports a busy worker whose job exceeded its timeout as unavailable', function (): void {
    $heartbeat = new WorkerHeartbeat('automations');
    $heartbeat->recordBusy('App\\Jobs\\ExecuteAutomationRun');

    // Automations allow 180 s plus a 60 s margin.
    $this->travel(300)->seconds();

    $group = (new WorkerHealth($heartbeat))->inspect()['automations'];

    expect($group['state'])->toBe(QueueAssessment::STATE_UNAVAILABLE)
        ->and($group['activity'])->toBe('busy');
});

it('reports a stale idle heartbeat as unavailable', function (): void {
    $heartbeat = new WorkerHeartbeat('interactive');
    $heartbeat->recordIdle();

    $this->travel(200)->seconds();

    $group = (new WorkerHealth($heartbeat))->inspect()['interactive'];

    expect($group['state'])->toBe(QueueAssessment::STATE_UNAVAILABLE)
        ->and($group['age_seconds'])->toBeGreaterThan(90);
});

it('degrades the AI capability without declaring the central operations down', function (): void {
    config([
        'queue.monitoring.queues' => ['default', 'ai-tasks'],
        'queue.monitoring.max_age_seconds' => 30,
        'queue.monitoring.max_backlog' => 1000,
    ]);

    $connection = Queue::connection('redis');
    $connection->getConnection()->rpush($connection->getQueue('ai-tasks'), json_encode([
        'displayName' => 'Probe',
        'job' => 'Probe',
        'maxTries' => 1,
        'createdAt' => now()->subSeconds(600)->getTimestamp(),
    ]));

    try {
        $assessment = (new QueueAssessment(new QueueInspector('redis')))->inspect();

        expect($assessment['capabilities']['ai']['state'])->toBe(QueueAssessment::STATE_DEGRADED)
            ->and($assessment['capabilities']['operations']['state'])->toBe(QueueAssessment::STATE_VERIFIED)
            ->and($assessment['state'])->toBe(QueueAssessment::STATE_DEGRADED);

        // Readiness still accepts work for the central operations.
        $this->getJson('/api/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.queues', true)
            ->assertJsonPath('capabilities.ai.state', QueueAssessment::STATE_DEGRADED);
    } finally {
        $connection->getConnection()->del($connection->getQueue('ai-tasks'));
    }
});

it('fails readiness when a central queue is over its threshold', function (): void {
    config([
        'queue.monitoring.queues' => ['default'],
        'queue.monitoring.max_age_seconds' => 30,
        'queue.monitoring.max_backlog' => 1000,
    ]);

    $connection = Queue::connection('redis');
    $connection->getConnection()->rpush($connection->getQueue('default'), json_encode([
        'displayName' => 'Probe',
        'job' => 'Probe',
        'maxTries' => 1,
        'createdAt' => now()->subSeconds(600)->getTimestamp(),
    ]));

    try {
        $this->getJson('/api/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('checks.queues', false);
    } finally {
        $connection->getConnection()->del($connection->getQueue('default'));
    }
});

it('runs the job exactly once when the heartbeat cannot be published', function (): void {
    $throwing = new class extends Repository
    {
        public function __construct()
        {
            parent::__construct(new ArrayStore);
        }

        public function get($key, $default = null): mixed
        {
            throw new RuntimeException('cache down');
        }

        public function put($key, $value, $ttl = null): bool
        {
            throw new RuntimeException('cache down');
        }
    };

    Cache::swap($throwing);
    config(['queue.default' => 'sync']);

    // The listener resolves the group from the real process environment. The
    // worker role is what makes it attempt (and fail) to publish, so the job
    // is exercised with a heartbeat that cannot be written.
    $previous = getenv('QUEUE_WORKER_GROUP');
    putenv('QUEUE_WORKER_GROUP=interactive');

    try {
        Obs01ProbeJob::$runs = 0;
        Obs01ProbeJob::dispatch();

        expect(Obs01ProbeJob::$runs)->toBe(1);

        // The listener itself never throws, whatever the cache does.
        $listener = new RecordWorkerHeartbeat(new WorkerHeartbeat);
        $listener->looping(new Looping('sync', 'default'));

        // And the job was not run a second time by the failed signal.
        expect(Obs01ProbeJob::$runs)->toBe(1);
    } finally {
        if ($previous === false) {
            putenv('QUEUE_WORKER_GROUP');
        } else {
            putenv('QUEUE_WORKER_GROUP='.$previous);
        }
    }
});

it('registers the heartbeat listener for the queue lifecycle events', function (): void {
    $events = app('events');

    foreach ([
        Looping::class,
        JobProcessing::class,
        JobProcessed::class,
        JobFailed::class,
        JobReleased::class,
        JobExceptionOccurred::class,
    ] as $event) {
        expect($events->hasListeners($event))->toBeTrue();
    }
});

it('gives every worker service an explicit group that matches the configured timeouts', function (): void {
    $compose = (string) file_get_contents(base_path('compose.yaml'));

    $expected = [
        'queue' => 'interactive',
        'queue-imports' => 'imports',
        'queue-ai' => 'ai',
        'queue-automations' => 'automations',
    ];

    foreach ($expected as $service => $group) {
        preg_match(
            '/^  '.preg_quote($service, '/').':\n(.*?)(?=^  [A-Za-z0-9_-]+:\n|\z)/ms',
            $compose,
            $matches,
        );

        expect($matches[1] ?? '')->toContain('QUEUE_WORKER_GROUP: '.$group);
    }

    // Every group has a timeout, and the busy window is the group's own
    // timeout plus the margin, so a long job is not mistaken for a dead worker.
    foreach (config('queue.worker_groups') as $group) {
        expect($group['timeout_seconds'])->toBeGreaterThan(0)
            ->and($group['queues'])->not->toBeEmpty();
    }

    $largest = max(array_column(config('queue.worker_groups'), 'timeout_seconds'));

    expect((int) config('queue.heartbeat.ttl_seconds'))->toBeGreaterThan($largest);
});

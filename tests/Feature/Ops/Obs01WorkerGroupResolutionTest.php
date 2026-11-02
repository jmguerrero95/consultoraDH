<?php

declare(strict_types=1);

use App\Services\Ops\WorkerHeartbeat;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * OBS-01-R1: the worker group comes from the process, not from cached config.
 *
 * `config:cache` freezes `queue.worker_group` at build time. The containers
 * share the same code and cache but each worker has its own
 * `QUEUE_WORKER_GROUP`, so a group read from the cached config is stale or
 * empty for every worker. The worker keeps processing jobs but never publishes
 * its heartbeat, and the status screen reports it as unknown.
 *
 * The real process environment is the only source that differs per container,
 * so it is read at run time with `getenv()` and validated against the declared
 * groups.
 */
function withWorkerProcessGroup(?string $group, callable $callback): mixed
{
    $previous = getenv('QUEUE_WORKER_GROUP');

    if ($group === null) {
        putenv('QUEUE_WORKER_GROUP');
    } else {
        putenv('QUEUE_WORKER_GROUP='.$group);
    }

    try {
        return $callback();
    } finally {
        if ($previous === false) {
            putenv('QUEUE_WORKER_GROUP');
        } else {
            putenv('QUEUE_WORKER_GROUP='.$previous);
        }
    }
}

it('resolves the group from the process environment even when the cached config disagrees', function (): void {
    // The frozen config was built in another container and names a different
    // group; the process itself is the imports worker.
    config(['queue.worker_group' => 'ai']);

    withWorkerProcessGroup('imports', function (): void {
        expect((new WorkerHeartbeat)->group())->toBe('imports');
    });
});

it('publishes no heartbeat when the process has no group, even if the cached config has one', function (): void {
    config(['queue.worker_group' => 'imports']);

    withWorkerProcessGroup(null, function (): void {
        $heartbeat = new WorkerHeartbeat;

        expect($heartbeat->group())->toBeNull();

        $heartbeat->recordIdle();

        expect(Cache::get(WorkerHeartbeat::CACHE_PREFIX.'imports'))->toBeNull();
    });
});

it('resolves each declared worker group from its own process environment', function (): void {
    // The same cached config for every worker, as in the real deployment.
    config(['queue.worker_group' => 'interactive']);

    foreach (['interactive', 'imports', 'ai', 'automations'] as $group) {
        withWorkerProcessGroup($group, function () use ($group): void {
            expect((new WorkerHeartbeat)->group())->toBe($group);
        });
    }
});

it('refuses an undeclared group so it cannot publish a false signal', function (): void {
    config(['queue.worker_group' => 'imports']);

    withWorkerProcessGroup('unknown-worker', function (): void {
        $heartbeat = new WorkerHeartbeat;

        expect($heartbeat->group())->toBeNull();

        $heartbeat->recordIdle();

        expect(Cache::get(WorkerHeartbeat::CACHE_PREFIX.'unknown-worker'))->toBeNull()
            ->and(Cache::get(WorkerHeartbeat::CACHE_PREFIX.'imports'))->toBeNull();
    });
});

it('keeps explicit injection available for tests', function (): void {
    withWorkerProcessGroup(null, function (): void {
        expect((new WorkerHeartbeat('automations'))->group())->toBe('automations');
    });
});

it('still publishes a heartbeat for a declared process group', function (): void {
    withWorkerProcessGroup('imports', function (): void {
        (new WorkerHeartbeat)->recordIdle(true);

        $snapshot = Cache::get(WorkerHeartbeat::CACHE_PREFIX.'imports');

        expect($snapshot)->toBeArray()
            ->and($snapshot['state'])->toBe('idle')
            ->and($snapshot['heartbeat_at'])->toBeInt();
    });
});

it('keeps the heartbeat isolated from a cache failure', function (): void {
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

    withWorkerProcessGroup('imports', function (): void {
        $heartbeat = new WorkerHeartbeat;

        expect(fn () => $heartbeat->recordBusy('App\\Jobs\\ApplyLegacyImport'))
            ->not->toThrow(Throwable::class);

        expect(fn () => $heartbeat->recordIdle(true))
            ->not->toThrow(Throwable::class);
    });
});

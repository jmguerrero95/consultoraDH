<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A short-lived signal written by a real `queue:work` process.
 *
 * ## Why the queue depth cannot answer this
 *
 * `reserved=0` means "nobody holds a job", not "a worker is alive". An empty
 * queue looks exactly the same with a healthy worker and with no worker at all.
 * This class records evidence from the process itself.
 *
 * ## What is written
 *
 *   - idle: the worker completed a polling loop and found nothing to do
 *   - busy: a job started, with its class name (never its arguments)
 *
 * The idle renewal is throttled so a worker polling every few seconds does not
 * write Redis on every iteration. A busy write is immediate, and the worker
 * state is reset to idle when the job finishes, fails or is released.
 *
 * ## Why a long job is not a dead worker
 *
 * A busy signal is judged against the group's own timeout plus a margin, not
 * against the short idle window. A legitimate 10-minute import therefore stays
 * healthy while it runs; only a job that exceeds its configured limit is
 * reported as unavailable.
 *
 * ## Failure isolation
 *
 * Every Redis/cache operation is wrapped. Observability is not allowed to stop
 * a business job: if the cache is down, the signal is lost and the worker keeps
 * working.
 */
final class WorkerHeartbeat
{
    public const CACHE_PREFIX = 'ops:worker-heartbeat:';

    /**
     * The variable each compose worker service sets for itself.
     *
     * It is read from the real process environment at run time. Reading it
     * from `config('queue.worker_group')` looks equivalent and is not: with
     * `config:cache` the value is frozen at build time, and every container
     * shares the same cached file. The workers then keep processing jobs while
     * their heartbeats are written under a stale or empty group — or not
     * written at all.
     */
    public const GROUP_ENV = 'QUEUE_WORKER_GROUP';

    public function __construct(private readonly ?string $group = null) {}

    public function group(): ?string
    {
        // An explicitly injected group wins (tests and controlled callers).
        // Otherwise the process environment is the only per-container source.
        $group = $this->group ?? getenv(self::GROUP_ENV);
        $group = is_string($group) ? trim($group) : '';

        if ($group === '') {
            // The web process and the scheduler have no worker group and
            // must not impersonate one.
            return null;
        }

        // A group that is not declared in configuration is a typo or a stale
        // variable, not evidence of a worker. Publishing under it would create
        // a heartbeat nobody reads, so it is refused instead.
        return $this->isDeclared($group) ? $group : null;
    }

    public function recordIdle(bool $force = false): void
    {
        $this->write('idle', null, null, $force);
    }

    public function recordBusy(string $job): void
    {
        $this->write('busy', $job, now()->getTimestamp(), true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function snapshot(string $group): ?array
    {
        try {
            $value = Cache::get(self::CACHE_PREFIX.$group);

            return is_array($value) ? $value : null;
        } catch (Throwable) {
            // A cache failure is "no evidence", never a healthy signal.
            return null;
        }
    }

    /**
     * Whether the group is one the application declared.
     *
     * `queue.worker_groups` is static configuration (its keys come from
     * `config/queue.php`, not from the environment), so it survives
     * `config:cache` intact. It is the list a group must belong to.
     */
    private function isDeclared(string $group): bool
    {
        $groups = config('queue.worker_groups', []);

        return is_array($groups) && array_key_exists($group, $groups);
    }

    private function write(string $state, ?string $job, ?int $jobStartedAt, bool $force): void
    {
        try {
            $group = $this->group();

            if ($group === null) {
                // The web process and the scheduler have no worker group and
                // must not impersonate one.
                return;
            }

            $key = self::CACHE_PREFIX.$group;
            $now = now()->getTimestamp();
            $renew = (int) config('queue.heartbeat.renew_seconds', 10);

            if (! $force) {
                $existing = Cache::get($key);

                if (is_array($existing)
                    && ($existing['state'] ?? null) === 'idle'
                    && is_numeric($existing['heartbeat_at'] ?? null)
                    && ($now - (int) $existing['heartbeat_at']) < $renew) {
                    return;
                }
            }

            Cache::put($key, [
                'state' => $state,
                'heartbeat_at' => $now,
                'job_started_at' => $jobStartedAt,
                'job' => $job,
            ], now()->addSeconds((int) config('queue.heartbeat.ttl_seconds', 900)));
        } catch (Throwable) {
            // Observability must never break the job it observes.
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Database\QueryException;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Reads the state of a queue without touching a job.
 *
 * ## Why it exists
 *
 * Two places need the same numbers: the `queue:health` command, which a monitor
 * runs, and the readiness endpoint, which a release process asks. When they
 * each read the queue on their own, they drift, and the number a monitor
 * alerts on is not the number the release gate saw.
 *
 * ## What it does not do
 *
 * It reports the queue, not the workers. A queue with no backlog proves
 * nothing about a worker: a dead worker also leaves an empty queue. The
 * difference between "nothing to do" and "nobody is doing it" is answered by
 * the age of the oldest pending job, which is why that is measured here too.
 */
final class QueueInspector
{
    public function __construct(private readonly string $connectionName = 'redis') {}

    /**
     * The connection this inspector reads.
     */
    public function connectionName(): string
    {
        return $this->connectionName;
    }

    public function pendingSize(string $queue): int
    {
        return (int) Queue::connection($this->connectionName)->pendingSize($queue);
    }

    public function delayedSize(string $queue): int
    {
        return (int) Queue::connection($this->connectionName)->delayedSize($queue);
    }

    public function reservedSize(string $queue): int
    {
        return (int) Queue::connection($this->connectionName)->reservedSize($queue);
    }

    /**
     * The age in seconds of the oldest job still waiting to be picked up.
     *
     * Null when the queue holds nothing, or when the connection cannot be
     * inspected: the caller then has no age to compare, which is not the same
     * as an age of zero.
     */
    public function oldestPendingSeconds(string $queue): ?int
    {
        $connection = Queue::connection($this->connectionName);

        try {
            if ($connection instanceof RedisQueue) {
                $payload = $connection->getConnection()->lindex($connection->getQueue($queue), 0);

                if (! is_string($payload)) {
                    return null;
                }

                $decoded = json_decode($payload, true);
                $createdAt = is_array($decoded) ? ($decoded['createdAt'] ?? null) : null;

                return is_numeric($createdAt)
                    ? max(0, now()->getTimestamp() - (int) $createdAt)
                    : null;
            }

            if ($connection instanceof DatabaseQueue) {
                $database = config("queue.connections.{$this->connectionName}.connection") ?: config('database.default');
                $table = (string) config("queue.connections.{$this->connectionName}.table", 'jobs');

                $createdAt = DB::connection($database)
                    ->table($table)
                    ->where('queue', $queue)
                    ->whereNull('reserved_at')
                    ->min('created_at');

                return is_numeric($createdAt)
                    ? max(0, now()->getTimestamp() - (int) $createdAt)
                    : null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * How many jobs failed outright, or null when there is no failed-jobs table.
     */
    public function failedCount(): ?int
    {
        try {
            return (int) DB::table('failed_jobs')->count();
        } catch (QueryException) {
            return null;
        } catch (Throwable) {
            return null;
        }
    }
}

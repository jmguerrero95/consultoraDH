<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Reports queue backlog, latency and failed jobs so a monitor can alert before
 * a worker becomes the silent bottleneck.
 *
 * The command is read-only. With `--strict` it exits non-zero when a queue is
 * over the configured backlog/age threshold or when failed jobs exist, which
 * is the shape a cron or an external monitor needs.
 */
final class QueueHealthCommand extends Command
{
    protected $signature = 'queue:health
        {--connection= : Queue connection to inspect (defaults to queue.default)}
        {--queue=* : Queue names to inspect (defaults to queue.monitoring.queues)}
        {--json : Print a machine readable report}
        {--strict : Exit non-zero when a threshold is exceeded}';

    protected $description = 'Report pending, delayed and reserved jobs, oldest job age and failed jobs';

    public function handle(): int
    {
        $connectionName = (string) ($this->option('connection') ?: config('queue.default'));
        $queues = $this->option('queue');

        if ($queues === []) {
            $queues = (array) config('queue.monitoring.queues', []);
        }

        $maxBacklog = (int) config('queue.monitoring.max_backlog', 1000);
        $maxAge = (int) config('queue.monitoring.max_age_seconds', 300);
        $maxFailed = (int) config('queue.monitoring.max_failed', 0);

        try {
            $connection = Queue::connection($connectionName);
        } catch (Throwable $exception) {
            return $this->failReport(
                $connectionName,
                $queues,
                $exception->getMessage(),
                $maxBacklog,
                $maxAge,
                $maxFailed,
            );
        }

        $report = [
            'status' => 'ok',
            'connection' => $connectionName,
            'checked_at' => now()->toIso8601String(),
            'queues' => [],
            'failed' => null,
            'thresholds' => [
                'max_backlog' => $maxBacklog,
                'max_age_seconds' => $maxAge,
                'max_failed' => $maxFailed,
            ],
        ];

        $degraded = false;

        foreach ($queues as $queue) {
            $queue = (string) $queue;

            try {
                $pending = (int) $connection->pendingSize($queue);
                $delayed = (int) $connection->delayedSize($queue);
                $reserved = (int) $connection->reservedSize($queue);
                $oldest = $this->oldestPendingSeconds($connectionName, $connection, $queue);
            } catch (Throwable $exception) {
                $report['status'] = 'degraded';
                $report['queues'][$queue] = [
                    'error' => $exception->getMessage(),
                ];
                $degraded = true;

                continue;
            }

            $overBacklog = $pending > $maxBacklog;
            $overAge = $oldest !== null && $oldest > $maxAge;

            if ($overBacklog || $overAge) {
                $degraded = true;
            }

            $report['queues'][$queue] = [
                'pending' => $pending,
                'delayed' => $delayed,
                'reserved' => $reserved,
                'oldest_pending_seconds' => $oldest,
                'over_backlog' => $overBacklog,
                'over_age' => $overAge,
            ];
        }

        try {
            $report['failed'] = (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            // A database queue that has never been migrated has no failed_jobs
            // table. That is not an outage of the inspected queue.
            $report['failed'] = null;
        }

        if ($report['failed'] !== null && $report['failed'] > $maxFailed) {
            $degraded = true;
        }

        if ($degraded) {
            $report['status'] = 'degraded';
        }

        $this->render($report, (bool) $this->option('json'));

        if ($this->option('strict') && $degraded) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Milliseconds are rounded down: a monitor only needs whole seconds.
     */
    private function oldestPendingSeconds(string $connectionName, mixed $connection, string $queue): ?int
    {
        if ($connection instanceof RedisQueue) {
            $payload = $connection->getConnection()->lindex($connection->getQueue($queue), 0);

            if (! is_string($payload)) {
                return null;
            }

            $decoded = json_decode($payload, true);
            $createdAt = $decoded['createdAt'] ?? null;

            return is_numeric($createdAt)
                ? max(0, now()->getTimestamp() - (int) $createdAt)
                : null;
        }

        if ($connection instanceof DatabaseQueue) {
            $database = config("queue.connections.{$connectionName}.connection") ?: config('database.default');
            $table = (string) config("queue.connections.{$connectionName}.table", 'jobs');

            $createdAt = DB::connection($database)
                ->table($table)
                ->where('queue', $queue)
                ->whereNull('reserved_at')
                ->min('created_at');

            return is_numeric($createdAt)
                ? max(0, now()->getTimestamp() - (int) $createdAt)
                : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report, bool $json): void
    {
        if ($json) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info("Queue health: {$report['status']} ({$report['connection']})");

        $rows = [];
        foreach ($report['queues'] as $queue => $metrics) {
            if (isset($metrics['error'])) {
                $rows[] = [$queue, 'error', '-', '-', '-', $metrics['error']];

                continue;
            }

            $rows[] = [
                $queue,
                $metrics['pending'],
                $metrics['delayed'],
                $metrics['reserved'],
                $metrics['oldest_pending_seconds'] ?? '-',
                ($metrics['over_backlog'] || $metrics['over_age']) ? 'over threshold' : 'ok',
            ];
        }

        $this->table(
            ['queue', 'pending', 'delayed', 'reserved', 'oldest (s)', 'state'],
            $rows,
        );

        $this->line('failed jobs: '.($report['failed'] ?? 'unknown'));
    }

    /**
     * @param  list<string>  $queues
     */
    private function failReport(
        string $connectionName,
        array $queues,
        string $error,
        int $maxBacklog,
        int $maxAge,
        int $maxFailed,
    ): int {
        $report = [
            'status' => 'degraded',
            'connection' => $connectionName,
            'checked_at' => now()->toIso8601String(),
            'queues' => [],
            'failed' => null,
            'error' => $error,
            'thresholds' => [
                'max_backlog' => $maxBacklog,
                'max_age_seconds' => $maxAge,
                'max_failed' => $maxFailed,
            ],
        ];

        foreach ($queues as $queue) {
            $report['queues'][(string) $queue] = ['error' => $error];
        }

        $this->render($report, (bool) $this->option('json'));

        return self::FAILURE;
    }
}

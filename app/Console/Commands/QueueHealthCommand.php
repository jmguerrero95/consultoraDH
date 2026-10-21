<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\QueueInspector;
use Illuminate\Console\Command;
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
        $inspector = new QueueInspector($connectionName);

        foreach ($queues as $queue) {
            $queue = (string) $queue;

            try {
                $pending = $inspector->pendingSize($queue);
                $delayed = $inspector->delayedSize($queue);
                $reserved = $inspector->reservedSize($queue);
                $oldest = $inspector->oldestPendingSeconds($queue);
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

        // A database queue that has never been migrated has no failed_jobs
        // table. That is not an outage of the inspected queue.
        $report['failed'] = $inspector->failedCount();

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

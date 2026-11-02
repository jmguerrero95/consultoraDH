<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\QueueAssessment;
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
            Queue::connection($connectionName);
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

        // One interpretation, shared with the HTTP endpoints. A failed
        // inspection is `degraded` here exactly as it is there.
        $assessment = (new QueueAssessment(new QueueInspector($connectionName)))->inspect($queues);

        $report = [
            'status' => $assessment['state'] === QueueAssessment::STATE_VERIFIED ? 'ok' : 'degraded',
            'connection' => $connectionName,
            'checked_at' => now()->toIso8601String(),
            'queues' => [],
            'failed' => $assessment['failed_jobs'],
            'failed_state' => $assessment['failed_state'],
            'capabilities' => $assessment['capabilities'],
            'thresholds' => $assessment['thresholds'],
        ];

        foreach ($assessment['queues'] as $queue) {
            $report['queues'][$queue['name']] = [
                'state' => $queue['state'],
                'pending' => $queue['pending'],
                'delayed' => $queue['delayed'],
                'reserved' => $queue['reserved'],
                'oldest_pending_seconds' => $queue['oldest_pending_seconds'],
                'oldest_status' => $queue['oldest_status'],
                'over_backlog' => $queue['over_backlog'],
                'over_age' => $queue['over_age'],
                'error' => $queue['error'],
            ];
        }

        $degraded = $report['status'] !== 'ok';

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
            if (($metrics['error'] ?? null) !== null) {
                $rows[] = [$queue, 'error', '-', '-', '-', $metrics['error']];

                continue;
            }

            $oldest = $metrics['oldest_pending_seconds']
                ?? (($metrics['oldest_status'] ?? null) === 'empty' ? 'empty' : 'unavailable');

            $rows[] = [
                $queue,
                $metrics['pending'],
                $metrics['delayed'],
                $metrics['reserved'],
                $oldest,
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

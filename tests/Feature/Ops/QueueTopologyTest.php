<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

function composeServiceBlock(string $name): string
{
    $compose = (string) file_get_contents(base_path('compose.yaml'));

    preg_match(
        '/^  '.preg_quote($name, '/').':\n(.*?)(?=^  [A-Za-z0-9_-]+:\n|\z)/ms',
        $compose,
        $matches,
    );

    return $matches[1] ?? '';
}

function composeServiceCommand(string $name): string
{
    $block = composeServiceBlock($name);

    preg_match('/^\s*command:\s*(.+)$/m', $block, $matches);

    return $matches[1] ?? '';
}

it('keeps every queue worker away from the import timeout', function (): void {
    $redis = config('queue.connections.redis');
    $importTimeout = (int) config('imports.queue.timeout');

    // The visibility timeout must be longer than the job budget. Otherwise
    // Redis releases a still-running import to a second worker.
    expect($redis['retry_after'])->toBeGreaterThan($importTimeout + 60);
    expect($redis['after_commit'])->toBeTrue();
    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan($importTimeout + 60);
    expect(config('queue.connections.database.after_commit'))->toBeTrue();
});

it('runs imports, interactive support, AI and automations on separate workers', function (): void {
    $interactive = composeServiceCommand('queue');
    $imports = composeServiceCommand('queue-imports');
    $ai = composeServiceCommand('queue-ai');
    $automations = composeServiceCommand('queue-automations');

    expect($interactive)->toContain('--queue=default,support,notifications')
        ->not->toContain('imports')
        ->toContain('--timeout=180');

    expect($imports)->toContain('--queue=imports')
        ->not->toContain('default')
        ->toContain('--timeout=630');

    expect($ai)->toContain('--queue=ai-tasks')
        ->toContain('--timeout=330');

    expect($automations)->toContain('--queue=automations')
        ->toContain('--timeout=180');
});

it('reports backlog, oldest job age and failed jobs as JSON', function (): void {
    $queue = 'health-probe-'.uniqid();
    $connection = Queue::connection('redis');
    $payload = json_encode([
        'displayName' => 'Probe',
        'job' => 'Probe',
        'maxTries' => 1,
        'createdAt' => now()->subSeconds(600)->getTimestamp(),
    ]);

    $connection->getConnection()->rpush($connection->getQueue($queue), $payload);

    try {
        config([
            'queue.monitoring.max_backlog' => 1000,
            'queue.monitoring.max_age_seconds' => 300,
        ]);

        Artisan::call('queue:health', [
            '--connection' => 'redis',
            '--queue' => [$queue],
            '--json' => true,
        ]);

        $report = json_decode(Artisan::output(), true);

        expect($report['status'])->toBe('degraded')
            ->and($report['connection'])->toBe('redis')
            ->and($report['queues'][$queue]['pending'])->toBe(1)
            ->and($report['queues'][$queue]['oldest_pending_seconds'])->toBeGreaterThanOrEqual(600)
            ->and($report['queues'][$queue]['over_age'])->toBeTrue()
            ->and($report['thresholds']['max_age_seconds'])->toBe(300);
    } finally {
        $connection->getConnection()->del($connection->getQueue($queue));
    }
});

it('exits non-zero when a strict monitor sees a backlog', function (): void {
    $queue = 'health-probe-'.uniqid();
    $connection = Queue::connection('redis');
    $payload = json_encode([
        'displayName' => 'Probe',
        'job' => 'Probe',
        'maxTries' => 1,
        'createdAt' => now()->getTimestamp(),
    ]);

    $connection->getConnection()->rpush($connection->getQueue($queue), $payload);

    try {
        config(['queue.monitoring.max_backlog' => 0]);

        $this->artisan('queue:health', [
            '--connection' => 'redis',
            '--queue' => [$queue],
            '--strict' => true,
        ])->assertExitCode(1);
    } finally {
        $connection->getConnection()->del($connection->getQueue($queue));
    }
});

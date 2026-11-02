<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Throwable;

/**
 * One interpretation of queue state, shared by every consumer.
 *
 * `SystemStatusController`, `HealthController` and `queue:health` used to read
 * the same inspector and judge the result with three slightly different rules.
 * A failed read could therefore be "verified" on one screen and "degraded" in
 * the command. This class is the single place where a measurement becomes a
 * state.
 *
 * ## States
 *
 *   - `verified`   every monitored queue answered and is inside its thresholds
 *   - `degraded`   a queue answered over a threshold, or its inspection failed
 *   - `unknown`    no monitored queue was configured, or no state could be read
 *
 * An inspection failure is `degraded`, never `verified` and never silently
 * treated as an empty queue.
 *
 * ## Core and optional capabilities
 *
 * A queue is a capability, not the whole platform. The `operations` capability
 * holds the queues that serve the main administrative work; the rest
 * (`notifications`, `imports`, `ai`, `automations`) are secondary. Readiness
 * uses the `operations` state only, so an AI backlog degrades its own module
 * without rejecting HTTP requests for clients, portfolio or payments.
 */
final class QueueAssessment
{
    public const STATE_VERIFIED = 'verified';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_UNAVAILABLE = 'unavailable';

    public const STATE_UNKNOWN = 'unknown';

    public function __construct(private readonly QueueInspector $inspector) {}

    /**
     * @param  list<string>|null  $queues  Override the configured queue list.
     * @return array{
     *     state: string,
     *     queues: array<string, array<string, mixed>>,
     *     capabilities: array<string, array<string, mixed>>,
     *     failed_jobs: int|null,
     *     failed_state: string,
     *     thresholds: array<string, int>
     * }
     */
    public function inspect(?array $queues = null): array
    {
        $monitored = $this->monitoredQueues($queues);
        $capabilities = $this->capabilities($monitored);

        $maxBacklog = (int) config('queue.monitoring.max_backlog', 1000);
        $maxAge = (int) config('queue.monitoring.max_age_seconds', 300);
        $maxFailed = (int) config('queue.monitoring.max_failed', 0);

        $results = [];

        foreach ($monitored as $queue) {
            try {
                $pending = $this->inspector->pendingSize($queue);
                $delayed = $this->inspector->delayedSize($queue);
                $reserved = $this->inspector->reservedSize($queue);
                $oldest = $this->inspector->oldestPendingSeconds($queue);
            } catch (Throwable) {
                // A read failure is evidence of a problem, not of an empty
                // queue. It can never produce a global `verified`.
                $results[$queue] = [
                    'name' => $queue,
                    'state' => self::STATE_DEGRADED,
                    'pending' => null,
                    'delayed' => null,
                    'reserved' => null,
                    'oldest_pending_seconds' => null,
                    'oldest_status' => 'unavailable',
                    'over_backlog' => false,
                    'over_age' => false,
                    'error' => 'No se pudo inspeccionar la cola.',
                ];

                continue;
            }

            $overBacklog = $pending > $maxBacklog;
            $overAge = $oldest !== null && $oldest > $maxAge;

            $results[$queue] = [
                'name' => $queue,
                'state' => ($overBacklog || $overAge) ? self::STATE_DEGRADED : self::STATE_VERIFIED,
                'pending' => $pending,
                'delayed' => $delayed,
                'reserved' => $reserved,
                'oldest_pending_seconds' => $oldest,
                // `empty` is a real measurement; `unavailable` never is.
                'oldest_status' => $oldest === null ? 'empty' : 'measured',
                'over_backlog' => $overBacklog,
                'over_age' => $overAge,
                'error' => null,
            ];
        }

        $capabilityResults = [];

        foreach ($capabilities as $key => $definition) {
            $states = [];

            foreach ($definition['queues'] as $queue) {
                if (isset($results[$queue])) {
                    $states[] = $results[$queue]['state'];
                }
            }

            $capabilityResults[$key] = [
                'label' => $definition['label'],
                'critical' => $definition['critical'],
                'state' => $this->aggregate($states),
                'queues' => $definition['queues'],
            ];
        }

        try {
            $failed = $this->inspector->failedCount();
        } catch (Throwable) {
            $failed = null;
        }

        $failedState = match (true) {
            $failed === null => self::STATE_UNKNOWN,
            $failed > $maxFailed => self::STATE_DEGRADED,
            default => self::STATE_VERIFIED,
        };

        $overall = $this->aggregate(array_column($capabilityResults, 'state'));

        // Failed jobs are an operational warning, not a global outage. They
        // affect the administrative state, while readiness keeps using the
        // `operations` capability only.
        if ($failedState === self::STATE_DEGRADED) {
            $overall = self::STATE_DEGRADED;
        }

        return [
            'state' => $overall,
            'queues' => $results,
            'capabilities' => $capabilityResults,
            'failed_jobs' => $failed,
            'failed_state' => $failedState,
            'thresholds' => [
                'max_backlog' => $maxBacklog,
                'max_age_seconds' => $maxAge,
                'max_failed' => $maxFailed,
            ],
        ];
    }

    /**
     * The readiness answer for the central administrative operations.
     *
     * When no central queue is monitored, nothing central can be failing, so
     * the answer is `verified`; that keeps the existing behaviour of a
     * synchronous deployment, which has no queue to inspect.
     */
    public function coreState(array $assessment): string
    {
        $state = $assessment['capabilities']['operations']['state'] ?? self::STATE_VERIFIED;

        return (string) $state;
    }

    /**
     * @param  list<string>|null  $queues
     * @return list<string>
     */
    private function monitoredQueues(?array $queues): array
    {
        $configured = $queues ?? (array) config('queue.monitoring.queues', []);

        return array_values(array_unique(array_filter(
            array_map(static fn ($queue): string => (string) $queue, $configured),
            static fn (string $queue): bool => $queue !== '',
        )));
    }

    /**
     * @param  list<string>  $monitored
     * @return array<string, array{label: string, critical: bool, queues: list<string>}>
     */
    private function capabilities(array $monitored): array
    {
        $configured = (array) config('queue.monitoring.capabilities', []);
        $capabilities = [];

        foreach ($configured as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $queues = array_values(array_map(
                static fn ($queue): string => (string) $queue,
                (array) ($definition['queues'] ?? []),
            ));

            // A capability whose queues are not monitored has no evidence to
            // report. Counting it as `unknown` would drag the whole platform
            // down for a queue the operator never asked about.
            $monitoredQueues = array_values(array_intersect($queues, $monitored));

            if ($monitoredQueues === []) {
                continue;
            }

            $capabilities[(string) $key] = [
                'label' => (string) ($definition['label'] ?? $key),
                'critical' => (bool) ($definition['critical'] ?? false),
                'queues' => $monitoredQueues,
            ];
        }

        // A queue the operator added to QUEUE_MONITORED_QUEUES but did not map
        // to a capability is treated as central: silently ignoring it would be
        // the same false health this class exists to remove.
        foreach ($monitored as $queue) {
            $mapped = false;

            foreach ($capabilities as $definition) {
                if (in_array($queue, $definition['queues'], true)) {
                    $mapped = true;

                    break;
                }
            }

            if (! $mapped) {
                $capabilities['operations'] ??= [
                    'label' => 'Operaciones',
                    'critical' => true,
                    'queues' => [],
                ];
                $capabilities['operations']['queues'][] = $queue;
            }
        }

        return $capabilities;
    }

    /**
     * @param  list<string>  $states
     */
    private function aggregate(array $states): string
    {
        if ($states === []) {
            return self::STATE_UNKNOWN;
        }

        if (in_array(self::STATE_DEGRADED, $states, true)
            || in_array(self::STATE_UNAVAILABLE, $states, true)) {
            return self::STATE_DEGRADED;
        }

        if (in_array(self::STATE_UNKNOWN, $states, true)) {
            return self::STATE_UNKNOWN;
        }

        return self::STATE_VERIFIED;
    }
}

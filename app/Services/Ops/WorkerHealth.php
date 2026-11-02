<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Carbon;

/**
 * Turns the stored worker signals into an honest per-group state.
 *
 * ## States
 *
 *   - `verified`     recent idle heartbeat, or a job inside its group timeout
 *   - `unavailable`  a heartbeat exists but is older than the allowed window
 *   - `unknown`      no heartbeat was ever recorded for the group
 *
 * A missing heartbeat is not `connected`: configuration and an empty queue do
 * not prove that a process exists. A busy worker is not stale while it is
 * inside its own timeout, which is what keeps a long import from being reported
 * as dead.
 */
final class WorkerHealth
{
    public function __construct(private readonly WorkerHeartbeat $heartbeat) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function inspect(): array
    {
        $groups = (array) config('queue.worker_groups', []);
        $result = [];

        foreach ($groups as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $result[(string) $key] = $this->inspectGroup((string) $key, $definition);
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     */
    public function overallState(array $groups): string
    {
        if ($groups === []) {
            return QueueAssessment::STATE_UNKNOWN;
        }

        $states = array_map(static fn (array $group): string => (string) ($group['state'] ?? 'unknown'), $groups);

        if (in_array(QueueAssessment::STATE_UNAVAILABLE, $states, true)
            || in_array(QueueAssessment::STATE_DEGRADED, $states, true)) {
            return QueueAssessment::STATE_DEGRADED;
        }

        if (in_array(QueueAssessment::STATE_UNKNOWN, $states, true)) {
            return QueueAssessment::STATE_UNKNOWN;
        }

        return QueueAssessment::STATE_VERIFIED;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function inspectGroup(string $key, array $definition): array
    {
        $label = (string) ($definition['label'] ?? $key);
        $queues = array_values(array_map(
            static fn ($queue): string => (string) $queue,
            (array) ($definition['queues'] ?? []),
        ));
        $timeout = (int) ($definition['timeout_seconds'] ?? 180);

        $base = [
            'group' => $key,
            'label' => $label,
            'queues' => $queues,
            'timeout_seconds' => $timeout,
            'state' => QueueAssessment::STATE_UNKNOWN,
            'activity' => 'unknown',
            'last_heartbeat' => null,
            'age_seconds' => null,
            'job' => null,
            'job_started_at' => null,
            'job_age_seconds' => null,
        ];

        $snapshot = $this->heartbeat->snapshot($key);

        if ($snapshot === null) {
            return [
                ...$base,
                'detail' => 'Sin latido registrado para este grupo de workers.',
            ];
        }

        $heartbeatAt = is_numeric($snapshot['heartbeat_at'] ?? null)
            ? (int) $snapshot['heartbeat_at']
            : null;

        if ($heartbeatAt === null) {
            return [
                ...$base,
                'detail' => 'El latido existe pero no se pudo interpretar.',
            ];
        }

        $age = max(0, now()->getTimestamp() - $heartbeatAt);
        $activity = (string) ($snapshot['state'] ?? '');

        $base['last_heartbeat'] = Carbon::createFromTimestamp($heartbeatAt)->toIso8601String();
        $base['age_seconds'] = $age;

        if ($activity === 'busy') {
            $jobStartedAt = is_numeric($snapshot['job_started_at'] ?? null)
                ? (int) $snapshot['job_started_at']
                : $heartbeatAt;
            $jobAge = max(0, now()->getTimestamp() - $jobStartedAt);
            $allowed = $timeout + (int) config('queue.heartbeat.busy_margin_seconds', 60);

            $base['activity'] = 'busy';
            $base['job'] = is_string($snapshot['job'] ?? null)
                ? class_basename($snapshot['job'])
                : null;
            $base['job_started_at'] = Carbon::createFromTimestamp($jobStartedAt)->toIso8601String();
            $base['job_age_seconds'] = $jobAge;

            if ($jobAge > $allowed) {
                return [
                    ...$base,
                    'state' => QueueAssessment::STATE_UNAVAILABLE,
                    'detail' => 'El trabajo en ejecución supera el límite del grupo ('.$allowed.' s).',
                ];
            }

            return [
                ...$base,
                'state' => QueueAssessment::STATE_VERIFIED,
                'detail' => 'Worker ocupado dentro de su límite de '.$timeout.' s.',
            ];
        }

        if ($activity !== 'idle') {
            return [
                ...$base,
                'detail' => 'El latido no declara un estado válido.',
            ];
        }

        $staleAfter = (int) config('queue.heartbeat.idle_stale_seconds', 90);
        $base['activity'] = 'idle';

        if ($age > $staleAfter) {
            return [
                ...$base,
                'state' => QueueAssessment::STATE_UNAVAILABLE,
                'detail' => 'Sin latido reciente ('.$age.' s > '.$staleAfter.' s).',
            ];
        }

        return [
            ...$base,
            'state' => QueueAssessment::STATE_VERIFIED,
            'detail' => 'Worker inactivo con latido reciente.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ops\QueueAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Machine readable health endpoints for monitoring and for the local toolchain.
 *
 * ## Two different questions
 *
 * `/api/health` answers "does this process answer?", and nginx's own
 * `/healthz` answers the same question without PHP. That is a *liveness*
 * probe, and it is supposed to be cheap: a container that is merely slow to
 * boot must not be restarted for it.
 *
 * `/api/ready` answers a different question: "can this deployment serve a
 * request and finish the work it accepts?". It therefore checks the things a
 * liveness probe deliberately ignores — the database, Redis, and the queues,
 * including the age of the oldest job still waiting. A deployment whose
 * workers are all dead passes a liveness probe and fails this one, which is
 * exactly the difference a release gate needs to see.
 *
 * ## What it does not report
 *
 * Neither endpoint reports software versions, host names, container names,
 * connection strings or any other fingerprinting detail. Precise PostgreSQL
 * and Redis versions remain available to an authenticated administrator on the
 * dashboard, where the audience has already been authorised.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static fn (): bool => DB::select('select 1') !== []),
            'redis' => $this->check(static fn (): bool => Redis::connection()->ping() !== null),
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * The readiness probe.
     *
     * ## What a visitor gets
     *
     * The answer and nothing else: whether the database, Redis and the queues
     * are in a state where this deployment can take work. The size of a queue,
     * the age of its oldest job and the number of failed jobs are **operational
     * metrics**, and an endpoint that a load balancer, a crawler or a curious
     * visitor can reach is not the place to publish them: they describe the
     * inside of the deployment, and they tell an attacker how busy it is and
     * whether its workers are alive.
     *
     * The detail lives in {@see self::operations()}, behind the same authority
     * that may read the application's configuration, and in the `queue:health`
     * command for whoever has console access.
     *
     * The queue check is what separates "nothing to do" from "nobody is doing
     * it": an empty queue is healthy, and a queue whose oldest job has been
     * waiting longer than the configured age is not, even though its backlog
     * is short.
     */
    public function readiness(QueueAssessment $assessment): JsonResponse
    {
        $result = $assessment->inspect();
        $coreState = $assessment->coreState($result);

        $checks = [
            'database' => $this->check(static fn (): bool => DB::select('select 1') !== []),
            'redis' => $this->check(static fn (): bool => Redis::connection()->ping() !== null),
            // Only the central administrative queues decide readiness. A
            // backlog in AI, imports, notifications or automations is reported
            // as a degraded capability, not as a platform-wide outage.
            'queues' => $coreState === QueueAssessment::STATE_VERIFIED,
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ready' : 'not_ready',
            'checks' => $checks,
            // States only: no queue depth, age or job identifiers.
            'capabilities' => $this->capabilityStates($result),
        ], $healthy ? 200 : 503);
    }

    /**
     * The operational detail behind the readiness answer.
     *
     * The numbers a release gate needs and a visitor must not have: the depth
     * of each queue, the age of its oldest job, the failed jobs and the
     * thresholds they are judged against.
     */
    public function operations(QueueAssessment $assessment): JsonResponse
    {
        $result = $assessment->inspect();
        $coreState = $assessment->coreState($result);
        $healthy = $coreState === QueueAssessment::STATE_VERIFIED;

        return response()->json([
            'status' => $healthy ? 'ready' : 'not_ready',
            // A secondary queue can be degraded while the central operations
            // remain ready; `overall_state` says so without changing `status`.
            'overall_state' => $result['state'],
            'queues' => $result['queues'],
            'capabilities' => $result['capabilities'],
            'failed_jobs' => $result['failed_jobs'],
            'failed_state' => $result['failed_state'],
            'thresholds' => $result['thresholds'],
        ], $healthy ? 200 : 503);
    }

    /**
     * @param  array<string, mixed>  $assessment
     * @return array<string, array{state: string, critical: bool}>
     */
    private function capabilityStates(array $assessment): array
    {
        $states = [];

        foreach ((array) ($assessment['capabilities'] ?? []) as $key => $capability) {
            $states[(string) $key] = [
                'state' => (string) ($capability['state'] ?? QueueAssessment::STATE_UNKNOWN),
                'critical' => (bool) ($capability['critical'] ?? false),
            ];
        }

        return $states;
    }

    /**
     * Run a probe and report reachability only.
     *
     * The exception is handled by the framework's exception handler, which
     * writes it to the log; nothing about it reaches the response.
     */
    private function check(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            return false;
        }
    }
}

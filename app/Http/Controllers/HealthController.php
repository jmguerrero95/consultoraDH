<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Machine readable health endpoint for monitoring and for the local toolchain.
 *
 * This endpoint is public, so it reveals only what a monitor needs: whether the
 * application as a whole is able to serve, and whether each dependency answers.
 *
 * It deliberately does NOT report software versions, host names, container
 * names, connection strings or any other fingerprinting detail. Precise
 * PostgreSQL and Redis versions remain available to an authenticated
 * administrator on the dashboard, where the audience has already been
 * authorised.
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

<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * What each model on each endpoint has actually done, and for how long to leave
 * it alone.
 *
 * ## Why a plain cooldown is not enough
 *
 * A fixed cooldown treats "this model timed out once" and "this model has failed
 * nine times in a row" the same way: both come back the moment the timer runs
 * out. On a free tier that is exactly backwards — the model that keeps failing
 * is the one that should be tried *least*, and the model that has been answering
 * is the one that should be tried *first*.
 *
 * So the state kept per (endpoint, model) is deliberately small and behavioural:
 * consecutive failures, the kind of the last failure, when it last answered and
 * how slowly. The cooldown grows with the failure streak (progressive recovery)
 * and is capped, so a model that failed once is retried in under a minute while
 * one that has failed repeatedly is set aside for longer — but never forever.
 *
 * ## Credentials are tracked per endpoint, failures per model
 *
 * A rejected credential or a global account rate limit is a property of the
 * *endpoint*, not of one model: switching models that share the key is not a
 * recovery, it is the same wall. Those two states are stored against the
 * endpoint hash so every model behind that credential is skipped together.
 *
 * ## Nothing sensitive is stored
 *
 * Only model ids, endpoint hashes, counters, timestamps, latency and a failure
 * *kind*. Never a prompt, never a response, never a key.
 */
class AiModelHealth
{
    private const RECORD_TTL = 86400;

    /**
     * @return array{fails: int, last_kind: ?string, last_at: ?int, cooldown_until: int, last_ok_at: ?int, last_latency_ms: ?int, last_status: ?int}
     */
    public function snapshot(ModelCandidate $candidate): array
    {
        $stored = Cache::get($this->recordKey($candidate));

        $empty = [
            'fails' => 0,
            'last_kind' => null,
            'last_at' => null,
            'cooldown_until' => 0,
            'last_ok_at' => null,
            'last_latency_ms' => null,
            'last_status' => null,
        ];

        return is_array($stored) ? array_merge($empty, $stored) : $empty;
    }

    public function recordSuccess(ModelCandidate $candidate, int $latencyMs): void
    {
        Cache::put($this->recordKey($candidate), [
            'fails' => 0,
            'last_kind' => null,
            'last_at' => now()->timestamp,
            'cooldown_until' => 0,
            'last_ok_at' => now()->timestamp,
            'last_latency_ms' => $latencyMs,
            'last_status' => 200,
        ], self::RECORD_TTL);

        // A model that answers proves its endpoint is neither disabled nor
        // rate limited, so both endpoint-level flags are cleared at once.
        Cache::forget($this->disabledKey($candidate));
        Cache::forget($this->rateLimitedKey($candidate));
    }

    public function recordFailure(ModelCandidate $candidate, AiProviderException $error, int $latencyMs): int
    {
        $previous = $this->snapshot($candidate);
        $fails = (int) $previous['fails'] + 1;

        $seconds = $this->cooldownFor($error, $fails);
        $until = now()->timestamp + $seconds;

        Cache::put($this->recordKey($candidate), [
            'fails' => $fails,
            'last_kind' => $error->kind,
            'last_at' => now()->timestamp,
            'cooldown_until' => $until,
            'last_ok_at' => $previous['last_ok_at'],
            'last_latency_ms' => $latencyMs,
            'last_status' => $error->status,
        ], self::RECORD_TTL);

        // An authentication failure is a property of the credential: disable the
        // whole endpoint so the same wrong key is not re-tried model by model.
        if ($error->kind === 'auth') {
            $this->markEndpointDisabled($candidate, (int) config('ai.health.credential_cooldown_seconds', 600));
        }

        // Only an account-wide limit justifies setting the endpoint aside:
        // choosing another model behind the same account limit is the same wall.
        // A 429 scoped to one model or one upstream provider cools that model
        // down and leaves the rest of the pool usable.
        if ($error->kind === 'rate_limited' && $error->accountWide) {
            $this->markEndpointRateLimited($candidate, $seconds);
        }

        return $seconds;
    }

    public function cooldownRemaining(ModelCandidate $candidate): int
    {
        $until = (int) $this->snapshot($candidate)['cooldown_until'];

        return max(0, $until - now()->timestamp);
    }

    public function inCooldown(ModelCandidate $candidate): bool
    {
        return $this->cooldownRemaining($candidate) > 0;
    }

    public function recentSuccess(ModelCandidate $candidate): bool
    {
        $okAt = $this->snapshot($candidate)['last_ok_at'];

        if ($okAt === null) {
            return false;
        }

        return (now()->timestamp - (int) $okAt) <= max(30, (int) config('ai.health.success_ttl_seconds', 900));
    }

    public function lastLatencyMs(ModelCandidate $candidate): ?int
    {
        $value = $this->snapshot($candidate)['last_latency_ms'];

        return $value === null ? null : (int) $value;
    }

    public function triesSoFar(ModelCandidate $candidate): int
    {
        return (int) $this->snapshot($candidate)['fails'];
    }

    public function endpointDisabled(ModelCandidate $candidate): bool
    {
        return (int) Cache::get($this->disabledKey($candidate), 0) > now()->timestamp;
    }

    public function endpointRateLimited(ModelCandidate $candidate): bool
    {
        return (int) Cache::get($this->rateLimitedKey($candidate), 0) > now()->timestamp;
    }

    public function markEndpointDisabled(ModelCandidate $candidate, int $seconds): void
    {
        Cache::put($this->disabledKey($candidate), now()->timestamp + max(1, $seconds), max(1, $seconds));
    }

    /**
     * Forget everything known about a candidate, so it is tried immediately.
     *
     * Used when a provider outage has passed and the operator wants the pool to
     * stop waiting out cooldowns accumulated during it.
     */
    public function reset(ModelCandidate $candidate): void
    {
        Cache::forget($this->recordKey($candidate));
        Cache::forget($this->disabledKey($candidate));
        Cache::forget($this->rateLimitedKey($candidate));
    }

    public function markEndpointRateLimited(ModelCandidate $candidate, int $seconds): void
    {
        Cache::put($this->rateLimitedKey($candidate), now()->timestamp + max(1, $seconds), max(1, $seconds));
    }

    /**
     * The whole health picture for the status endpoint.
     *
     * @param  list<ModelCandidate>  $candidates
     * @return list<array<string, mixed>>
     */
    public function report(array $candidates): array
    {
        $report = [];

        foreach ($candidates as $candidate) {
            $snapshot = $this->snapshot($candidate);
            $cooldown = $this->cooldownRemaining($candidate);

            $state = 'unchecked';

            if ($this->endpointDisabled($candidate)) {
                $state = 'credential_rejected';
            } elseif ($this->endpointRateLimited($candidate)) {
                $state = 'quota_limited';
            } elseif ($cooldown > 0) {
                $state = 'cooling';
            } elseif ($this->recentSuccess($candidate)) {
                $state = 'ok';
            } elseif (($snapshot['last_at'] ?? null) !== null) {
                $state = 'degraded';
            }

            $report[] = [
                'model' => $candidate->model,
                'provider' => $candidate->providerHost(),
                'priority' => $candidate->priority,
                'tools' => $candidate->supportsTools(),
                'state' => $state,
                'cooldown_seconds' => $cooldown,
                'fails' => (int) $snapshot['fails'],
                'last_kind' => $snapshot['last_kind'],
                'last_ok_seconds_ago' => $snapshot['last_ok_at'] === null ? null : max(0, now()->timestamp - (int) $snapshot['last_ok_at']),
                'last_latency_ms' => $snapshot['last_latency_ms'] === null ? null : (int) $snapshot['last_latency_ms'],
            ];
        }

        return $report;
    }

    private function cooldownFor(AiProviderException $error, int $fails): int
    {
        if ($error->kind === 'not_found') {
            return (int) config('ai.health.model_missing_cooldown_seconds', 21600);
        }

        $base = max(5, (int) config('ai.health.base_cooldown_seconds', 45));
        $max = max($base, (int) config('ai.health.max_cooldown_seconds', 1800));

        // A provider that told us when to come back is believed over our own
        // backoff: Retry-After is the authority on its own limit.
        if ($error->retryAfterSeconds !== null && $error->retryAfterSeconds > 0) {
            return min(max($error->retryAfterSeconds, 5), $max);
        }

        // 45s, 90s, 180s ... capped. Never exponential without a ceiling.
        $seconds = $base * (2 ** min($fails - 1, 10));

        return (int) min($seconds, $max);
    }

    private function recordKey(ModelCandidate $candidate): string
    {
        return 'ai.health.'.substr(sha1($candidate->key()), 0, 24);
    }

    private function disabledKey(ModelCandidate $candidate): string
    {
        return 'ai.endpoint.disabled.'.$candidate->endpointHash();
    }

    private function rateLimitedKey(ModelCandidate $candidate): string
    {
        return 'ai.endpoint.ratelimited.'.$candidate->endpointHash();
    }
}

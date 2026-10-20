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
     * How long a model keeps the credit for having used a tool.
     *
     * Long enough to order a session's tool turns, short enough that yesterday's
     * evidence does not outrank a model that is answering tools today.
     */
    private const TOOLS_DEMONSTRATED_TTL = 3600;

    /**
     * @return array{fails: int, last_kind: ?string, last_scope: ?string, last_at: ?int, cooldown_until: int, last_ok_at: ?int, last_latency_ms: ?int, last_status: ?int}
     */
    public function snapshot(ModelCandidate $candidate): array
    {
        $stored = Cache::get($this->recordKey($candidate));

        $empty = [
            'fails' => 0,
            'last_kind' => null,
            'last_scope' => null,
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
        // rate limited, so both endpoint-level flags are cleared at once, and the
        // credential-failure streak is forgotten: a working key is a fresh start.
        Cache::forget($this->disabledKey($candidate));
        Cache::forget($this->rateLimitedKey($candidate));
        Cache::forget($this->credentialFailsKey($candidate));
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
            'last_scope' => $error->scope,
            'last_at' => now()->timestamp,
            'cooldown_until' => $until,
            'last_ok_at' => $previous['last_ok_at'],
            'last_latency_ms' => $latencyMs,
            'last_status' => $error->status,
        ], self::RECORD_TTL);

        // An authentication failure is a property of the credential: disable the
        // whole endpoint so the same wrong key is not re-tried model by model.
        //
        // BUG-021. The cooldown escalates with repeated rejections. A single
        // fixed 600s window meant a still-denied credential was re-probed as a
        // side effect of ordinary questions every ten minutes, so users kept
        // hitting a failing turn for a restriction that had not changed. Growing
        // the window (600s, 20m, 40m, ... capped at a day) makes the failed probe
        // rare, while the cap — and the fact that correcting the key yields a new
        // endpoint hash and therefore fresh state — keeps recovery possible.
        if ($error->kind === 'auth') {
            $this->markEndpointDisabled($candidate, $this->credentialCooldownFor($candidate));
        }

        // Only an account-wide limit justifies setting the endpoint aside:
        // choosing another model behind the same account limit is the same wall.
        // A 429 scoped to one model or one upstream provider cools that model
        // down and leaves the rest of the pool usable.
        //
        // A *daily* account limit is not the ordinary cooldown. OpenRouter's
        // `free-models-per-day` cannot move until the day rolls over, so the
        // account is suspended for the rest of the UTC day (or the provider's
        // Retry-After, if it is longer) instead of being re-probed every half
        // hour. Independent providers are unaffected: the flag is keyed by the
        // endpoint (URL + credential), not the pool.
        if ($error->kind === 'rate_limited' && $error->accountWide) {
            $window = $error->dailyLimit
                ? max($seconds, $this->secondsUntilDailyReset())
                : $seconds;

            $this->markEndpointRateLimited($candidate, $window);
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

    /**
     * How long the endpoint stays set aside for an account quota, in seconds.
     *
     * Exposed so a caller can tell a per-minute wait (seconds) from a daily one
     * (hours) without reading the cache key, and so the status can report the
     * difference.
     */
    public function endpointRateLimitedRemaining(ModelCandidate $candidate): int
    {
        return max(0, (int) Cache::get($this->rateLimitedKey($candidate), 0) - now()->timestamp);
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
        Cache::forget($this->credentialFailsKey($candidate));
        Cache::forget($this->toolsUnsupportedKey($candidate));
        Cache::forget($this->toolsDemonstratedKey($candidate));
    }

    /**
     * Remember that this model refused tool calling and serves conversation only.
     *
     * Recorded when the provider answers a tool payload with a 400/422 that names
     * tools. It is not a cooldown: the model is healthy, it simply cannot do this
     * one thing, so it stays in the pool for general questions and is skipped when
     * the turn needs a tool.
     */
    public function markToolsUnsupported(ModelCandidate $candidate): void
    {
        Cache::put($this->toolsUnsupportedKey($candidate), now()->timestamp, self::RECORD_TTL);
    }

    /**
     * Whether this model has shown it cannot do tool calling.
     */
    public function toolsUnsupported(ModelCandidate $candidate): bool
    {
        return Cache::get($this->toolsUnsupportedKey($candidate)) !== null;
    }

    public function markEndpointRateLimited(ModelCandidate $candidate, int $seconds): void
    {
        Cache::put($this->rateLimitedKey($candidate), now()->timestamp + max(1, $seconds), max(1, $seconds));
    }

    /**
     * Remember that this model actually emitted a structured tool call.
     *
     * Distinct from a cooldown and from the negative `toolsUnsupported` flag:
     * this is *positive* evidence that the model can use the tool catalogue when
     * the turn needs one, so a tool-bearing turn knows which candidates are worth
     * trying first. It is deliberately a plain, expiring observation — not a
     * ranking the model cannot lose — so a model that miscarries once on one
     * turn is not marked for it.
     */
    public function markToolsDemonstrated(ModelCandidate $candidate): void
    {
        Cache::put($this->toolsDemonstratedKey($candidate), now()->timestamp, self::TOOLS_DEMONSTRATED_TTL);
    }

    /**
     * Whether this model has been seen using tools rather than only talking.
     */
    public function toolsDemonstrated(ModelCandidate $candidate): bool
    {
        return Cache::get($this->toolsDemonstratedKey($candidate)) !== null;
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
                'tools' => $candidate->supportsTools() && ! $this->toolsUnsupported($candidate),
                'state' => $state,
                'cooldown_seconds' => $cooldown,
                'fails' => (int) $snapshot['fails'],
                'last_kind' => $snapshot['last_kind'],
                'last_scope' => $snapshot['last_scope'],
                'last_ok_seconds_ago' => $snapshot['last_ok_at'] === null ? null : max(0, now()->timestamp - (int) $snapshot['last_ok_at']),
                'last_latency_ms' => $snapshot['last_latency_ms'] === null ? null : (int) $snapshot['last_latency_ms'],
            ];
        }

        return $report;
    }

    /**
     * How long to set a rejected credential aside, growing with each rejection.
     *
     * The first refusal costs `credential_cooldown_seconds`; each further refusal
     * behind the same key doubles it, capped at
     * `credential_max_cooldown_seconds`. The counter is per endpoint, so a key
     * that is corrected (which changes the endpoint hash) starts clean, and a
     * key that is not corrected stops being probed on ordinary questions long
     * before the cap is reached.
     */
    private function credentialCooldownFor(ModelCandidate $candidate): int
    {
        $key = $this->credentialFailsKey($candidate);
        $fails = (int) Cache::get($key, 0) + 1;

        $base = max(60, (int) config('ai.health.credential_cooldown_seconds', 600));
        $max = max($base, (int) config('ai.health.credential_max_cooldown_seconds', 86400));

        $seconds = (int) min($base * (2 ** min($fails - 1, 10)), $max);

        // Kept longer than any cooldown it can produce, so the streak survives to
        // the next refusal and the escalation actually escalates.
        Cache::put($key, $fails, 172800);

        return $seconds;
    }

    /**
     * How long to set the endpoint aside for a *daily* account quota.
     *
     * A daily limit resets on a fixed clock, not a rolling one. OpenRouter counts
     * free-model requests against the UTC day, so the suspension runs to the next
     * UTC midnight — the honest reading of "per day" — with a floor of one minute
     * so a clock skew cannot produce a zero-length window. Nothing is invented
     * beyond that: if the provider sent a Retry-After, the caller takes whichever
     * is longer.
     */
    private function secondsUntilDailyReset(): int
    {
        $now = now('UTC');

        return max(60, $now->copy()->endOfDay()->getTimestamp() - $now->getTimestamp());
    }

    private function cooldownFor(AiProviderException $error, int $fails): int
    {
        if ($error->kind === 'not_found') {
            return (int) config('ai.health.model_missing_cooldown_seconds', 21600);
        }

        // An empty body is not evidence that the endpoint is down: the same
        // model often answers on an immediate re-ask, and the assistant service
        // already bounds that recovery to one extra call. Cooling the model here
        // made that recovery impossible — it was skipped as "in cooldown" — and
        // turned a transient blank into a 45-second outage. The failure is still
        // recorded and still fails over to another candidate within this turn.
        if ($error->kind === 'empty') {
            return 0;
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

    private function credentialFailsKey(ModelCandidate $candidate): string
    {
        return 'ai.endpoint.credential_fails.'.$candidate->endpointHash();
    }

    private function toolsUnsupportedKey(ModelCandidate $candidate): string
    {
        return 'ai.model.no_tools.'.substr(sha1($candidate->key()), 0, 24);
    }

    private function toolsDemonstratedKey(ModelCandidate $candidate): string
    {
        return 'ai.model.tools_ok.'.substr(sha1($candidate->key()), 0, 24);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiMessage;
use App\Domain\Ai\AiResponse;
use App\Domain\Ai\AiToolDefinition;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Chooses which model answers, in what order, and remembers what has worked.
 *
 * ## From two slots to a pool
 *
 * The candidates are no longer "primary then fallback". They are every model the
 * deployment may use — the two slots for backward compatibility, plus an
 * optional explicit pool, plus the free OpenRouter models the catalogue knows
 * about — filtered by the capabilities the turn actually needs and ordered by
 * what has recently worked.
 *
 * ## Ordering is adaptive, not round-robin
 *
 * A model that answered a moment ago goes first; a model that has never been
 * tried goes next, in configured order; a model that has been failing goes last.
 * That is the difference that matters on a free tier: the primary that has timed
 * out five times does not get the first sixty seconds of every question just
 * because its cooldown expired. It is retried — progressively, after a longer
 * pause each time — but behind the models that are answering.
 *
 * ## Failures are classified and remembered, not just caught
 *
 * A rejected credential disables the whole endpoint (every model behind that
 * key), a global rate limit does the same for as long as the provider said, a
 * missing model is parked for hours, and a timeout cools only its own model down
 * briefly. None of those are "try the next model and hope".
 *
 * ## The bound
 *
 * One turn makes at most `ai.max_attempts` provider calls, so a pool of sixteen
 * models cannot turn a single question into sixteen timeouts.
 */
class AiProviderResolver
{
    private const DIAGNOSTICS_KEY = 'ai.diagnostics.recent';

    private const LAST_RESULT_KEY = 'ai.last_result';

    public function __construct(
        private readonly ModelPool $pool,
        private readonly AiModelHealth $health,
    ) {}

    /**
     * @param  list<AiToolDefinition>  $tools
     * @param  list<AiMessage>  $messages
     * @param  string|null  $task  a safe label for diagnostics ('chat', 'data', ...)
     * @param  float|null  $deadline  unix timestamp by which the whole turn must finish
     *
     * @throws AiProviderException when no candidate can answer
     */
    public function complete(
        array $messages,
        array $tools = [],
        ?CloudAiProvider $preferred = null,
        ?string $task = null,
        ?float $deadline = null,
    ): AiResponse {
        $required = $tools !== [] ? ['chat', 'tools'] : ['chat'];

        $candidates = $this->ordered($this->pool->supporting($required), $preferred);

        if ($candidates === []) {
            throw $this->pool->all() === []
                ? AiProviderException::notConfigured()
                : AiProviderException::unavailable(
                    $tools !== []
                        ? 'No hay ningún modelo configurado que admita herramientas en este momento.'
                        : 'No hay ningún modelo de IA configurado y disponible.'
                );
        }

        // The turn's wall-clock budget. A free model can be slow, and several of
        // them in a row must still finish inside the proxy's own limit, so the
        // budget — not a fixed per-call timeout — is what bounds the turn.
        $deadline ??= microtime(true) + max(5, (int) config('ai.total_budget_seconds', 110));

        $maxAttempts = max(1, (int) config('ai.max_attempts', 4));
        $configuredTimeout = max(3, (int) config('ai.timeout_seconds', 30));
        $attempts = 0;
        $lastFailure = null;
        $skipped = 0;
        $skippedRateLimited = 0;
        $outOfTime = false;

        foreach ($candidates as $candidate) {
            if ($attempts >= $maxAttempts) {
                break;
            }

            $remaining = (int) floor($deadline - microtime(true));

            if ($remaining < 3) {
                $outOfTime = true;

                break;
            }

            if ($this->health->endpointDisabled($candidate)) {
                $skipped++;

                continue;
            }

            if ($this->health->endpointRateLimited($candidate)) {
                $skipped++;
                $skippedRateLimited++;

                continue;
            }

            if ($this->health->inCooldown($candidate)) {
                $skipped++;

                continue;
            }

            $attempts++;
            $startedAt = microtime(true);

            // Never wait longer than the turn has left, and never longer than the
            // operator's per-attempt ceiling.
            $attemptTimeout = max(3, min($configuredTimeout, $remaining));

            try {
                $response = $this->providerFor($candidate)->complete($messages, $tools, $attemptTimeout);
            } catch (AiProviderException $e) {
                $latency = $this->latenciesFrom($startedAt);
                $this->health->recordFailure($candidate, $e, $latency);
                $this->recordDiagnostic($task, $candidate, $e, $latency, 'failed');

                $lastFailure = $e;

                if (! $e->retryable) {
                    throw $e;
                }

                continue;
            } catch (Throwable $e) {
                // A failure this application did not classify is still that one
                // model's problem: it is cooled down and the turn moves on rather
                // than dying because a candidate returned something unexpected.
                $converted = AiProviderException::unavailable($e::class.': '.$e->getMessage());
                $latency = $this->latenciesFrom($startedAt);
                $this->health->recordFailure($candidate, $converted, $latency);
                $this->recordDiagnostic($task, $candidate, $converted, $latency, 'failed');
                $lastFailure = $converted;

                continue;
            }

            $latency = $this->latenciesFrom($startedAt);
            $this->health->recordSuccess($candidate, $latency);
            $this->recordDiagnostic($task, $candidate, null, $latency, 'ok');
            $this->rememberLastResult($candidate);

            return $response;
        }

        if ($lastFailure !== null) {
            throw $lastFailure;
        }

        // Nothing was attempted: every candidate was cooling down, disabled or
        // rate limited, or the turn's budget was already spent. Reported as
        // temporary unavailability, not as a configuration problem.
        if ($outOfTime) {
            throw AiProviderException::unavailable(
                'La consulta agotó el tiempo disponible antes de que un modelo respondiera.'
            );
        }

        if ($skippedRateLimited > 0) {
            throw AiProviderException::rateLimited(
                'La cuota de los proveedores de IA está agotada temporalmente'
                .($skipped > 0 ? " ({$skipped} en espera)." : '.')
            );
        }

        throw AiProviderException::unavailable(
            'Todos los modelos de IA están temporalmente en espera tras fallos recientes'
            .($skipped > 0 ? " ({$skipped} en espera)." : '.')
        );
    }

    /**
     * The backward-compatible primary slot, as a provider.
     */
    public function primary(): CloudAiProvider
    {
        return new OpenAiCompatibleProvider($this->slot('primary'), 'primary');
    }

    public function fallback(): CloudAiProvider
    {
        return new OpenAiCompatibleProvider($this->slot('fallback'), 'fallback');
    }

    public function anyConfigured(): bool
    {
        return $this->pool->all() !== [];
    }

    /**
     * Which state the assistant is in, from configuration and real recent health.
     *
     * `none`             — nothing is configured.
     * `available`        — a model that recently answered is usable now.
     * `fallback`         — the first candidate is unusable but another is not.
     * `unchecked`        — configured, usable, but no recent proof it answers.
     * `quota_limited`    — every usable candidate is behind a provider quota wall.
     * `unavailable`      — configured but every candidate is cooling down.
     */
    public function state(): string
    {
        $candidates = $this->pool->all();

        if ($candidates === []) {
            return 'none';
        }

        $ordered = $this->ordered($candidates);

        $usable = array_values(array_filter(
            $ordered,
            fn (ModelCandidate $c): bool => ! $this->blocked($c),
        ));

        if ($usable === []) {
            foreach ($candidates as $candidate) {
                if ($this->health->endpointRateLimited($candidate)) {
                    return 'quota_limited';
                }
            }

            return 'unavailable';
        }

        $topUsable = ! $this->blocked($ordered[0]);
        $firstUsable = $usable[0];

        if ($this->health->recentSuccess($firstUsable)) {
            return $topUsable ? 'available' : 'fallback';
        }

        return $topUsable ? 'unchecked' : 'fallback';
    }

    /**
     * The model that would answer a question asked right now, or null.
     */
    public function activeModel(): ?string
    {
        $ordered = $this->ordered($this->pool->all());

        foreach ($ordered as $candidate) {
            if (! $this->blocked($candidate)) {
                return $candidate->model;
            }
        }

        // Nothing is usable right now: still name the model that answered last,
        // so the interface can say what was working rather than nothing at all.
        return $this->lastResult()['model'] ?? null;
    }

    /**
     * How many seconds each model is still cooling down for, keyed by label.
     *
     * @return array<string, int>
     */
    public function cooldowns(): array
    {
        $remaining = [];

        foreach ($this->pool->all() as $candidate) {
            $seconds = $this->health->cooldownRemaining($candidate);

            if ($seconds > 0) {
                $remaining[$candidate->label()] = $seconds;
            }
        }

        return $remaining;
    }

    /**
     * The health snapshot of every candidate, for the status endpoint.
     *
     * @return list<array<string, mixed>>
     */
    public function snapshot(): array
    {
        return $this->health->report($this->ordered($this->pool->all()));
    }

    /**
     * The most recent provider attempts, safe metadata only.
     *
     * @return list<array<string, mixed>>
     */
    public function diagnostics(): array
    {
        $rows = Cache::get(self::DIAGNOSTICS_KEY);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array{model: ?string, provider: ?string, at: ?int}|null
     */
    public function lastResult(): ?array
    {
        $stored = Cache::get(self::LAST_RESULT_KEY);

        return is_array($stored) ? $stored : null;
    }

    /**
     * @return list<ModelCandidate>
     */
    public function pool(): array
    {
        return $this->pool->all();
    }

    /**
     * Order candidates by what has recently worked, then by configuration.
     *
     * Health is read once per candidate and folded into a sort key, rather than
     * re-read inside the comparator: a comparator runs O(n log n) times and each
     * read is a cache round trip.
     *
     * @param  list<ModelCandidate>  $candidates
     * @return list<ModelCandidate>
     */
    private function ordered(array $candidates, ?CloudAiProvider $preferred = null): array
    {
        $wanted = $preferred?->model();
        $decorated = [];

        foreach ($candidates as $candidate) {
            $key = $this->orderKey($candidate);

            if (is_string($wanted) && $wanted !== '' && $candidate->model === $wanted) {
                $key = [-1, 0, 0];
            }

            $decorated[] = ['key' => $key, 'candidate' => $candidate];
        }

        usort($decorated, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return array_map(static fn (array $row): ModelCandidate => $row['candidate'], $decorated);
    }

    /**
     * @return array{int, int, int}
     */
    private function orderKey(ModelCandidate $candidate): array
    {
        $snapshot = $this->health->snapshot($candidate);
        $okAt = $snapshot['last_ok_at'];
        $ttl = max(30, (int) config('ai.health.success_ttl_seconds', 900));

        if ($okAt !== null && (now()->timestamp - (int) $okAt) <= $ttl) {
            // Proven to work: fastest first.
            return [0, $snapshot['last_latency_ms'] === null ? 999999 : (int) $snapshot['last_latency_ms'], $candidate->priority];
        }

        if (($snapshot['last_at'] ?? null) === null) {
            // Never tried: honour the configured order.
            return [1, $candidate->priority, 0];
        }

        // Tried and currently unproven: fewest failures first, then order.
        return [2, (int) $snapshot['fails'], $candidate->priority];
    }

    private function blocked(ModelCandidate $candidate): bool
    {
        return $this->health->endpointDisabled($candidate)
            || $this->health->endpointRateLimited($candidate)
            || $this->health->inCooldown($candidate);
    }

    private function providerFor(ModelCandidate $candidate): CloudAiProvider
    {
        return new OpenAiCompatibleProvider(
            $candidate->toConfig(),
            $candidate->label(),
            $this->internalFallbackModels($candidate),
        );
    }

    /**
     * OpenRouter's own ordered fallback list, only when explicitly enabled.
     *
     * It is off by default because the resolver already walks the pool; enabling
     * both at once would send the same alternatives twice. When enabled it is
     * limited to the remaining tool-capable OpenRouter candidates.
     *
     * @return list<string>
     */
    private function internalFallbackModels(ModelCandidate $candidate): array
    {
        if (! (bool) config('ai.openrouter.use_models_fallback', false) || ! $candidate->isOpenRouter()) {
            return [];
        }

        $models = [];

        foreach ($this->ordered($this->pool->supporting(['chat', 'tools'])) as $other) {
            if ($other->model !== $candidate->model && $other->isOpenRouter()) {
                $models[] = $other->model;
            }
        }

        return array_slice($models, 0, 4);
    }

    private function latenciesFrom(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function rememberLastResult(ModelCandidate $candidate): void
    {
        Cache::put(self::LAST_RESULT_KEY, [
            'model' => $candidate->model,
            'provider' => $candidate->providerHost(),
            'at' => now()->timestamp,
        ], 3600);
    }

    private function recordDiagnostic(
        ?string $task,
        ModelCandidate $candidate,
        ?AiProviderException $error,
        int $latencyMs,
        string $outcome,
    ): void {
        $rows = $this->diagnostics();

        array_unshift($rows, [
            'at' => now()->timestamp,
            'task' => $task,
            'model' => $candidate->model,
            'provider' => $candidate->providerHost(),
            'outcome' => $outcome,
            'kind' => $error?->kind,
            'http' => $error?->status,
            'scope' => $error?->accountWide === true ? 'account' : null,
            // The provider's own reason for refusing, truncated. It describes the
            // shape of the request, never the person's text or the credential, and
            // it is what turns "malformed (400)" into something actionable.
            'detail' => $error === null ? null : mb_substr($error->getMessage(), 0, 200),
            'latency_ms' => $latencyMs,
        ]);

        Cache::put(self::DIAGNOSTICS_KEY, array_slice($rows, 0, 20), 3600);
    }

    /**
     * @return array{base_url: ?string, api_key: ?string, model: ?string}
     */
    private function slot(string $name): array
    {
        return (array) config("ai.{$name}", ['base_url' => null, 'api_key' => null, 'model' => null]);
    }
}

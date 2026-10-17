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
 * A user turn — every round of it — makes at most the turn budget's provider
 * calls, so a pool of sixteen models cannot turn a single question into sixteen
 * timeouts, and a tool round cannot restart the count and spend the budget twice.
 * The budget is a shared object the assistant service creates once and passes to
 * every `complete()`, not a counter local to this method.
 *
 * ## A refused credential is not the end of the turn
 *
 * A 401/403 belongs to one provider's key. The endpoint is quarantined (with an
 * escalating window, so a still-denied key stops being probed on ordinary
 * questions) and the turn continues on the next *independent* provider. Only two
 * scopes are terminal: a content-policy refusal, which must not be evaded by
 * asking elsewhere, and an application/configuration fault, which no other
 * provider can fix.
 */
class AiProviderResolver
{
    private const DIAGNOSTICS_KEY = 'ai.diagnostics.recent';

    private const LAST_RESULT_KEY = 'ai.last_result';

    public function __construct(
        private readonly ModelPool $pool,
        private readonly AiModelHealth $health,
        private readonly AiProviderQuota $quota,
        private readonly AiPrivacyGate $privacy,
    ) {}

    /**
     * @param  list<AiToolDefinition>  $tools
     * @param  list<AiMessage>  $messages
     * @param  string|null  $task  a safe label for diagnostics ('chat', 'data', ...)
     * @param  float|null  $deadline  unix timestamp by which the whole turn must finish
     * @param  bool|null  $requiresClientData  true when the turn carries real business
     *                                         data, so a provider whose free tier may
     *                                         train on the content is not eligible. It is
     *                                         required: `null` (the argument omitted) is a
     *                                         refusal, never an implicit "public".
     * @param  AiTurnBudget|null  $budget  the turn's shared attempt/time budget. Passed
     *                                     by the assistant service so every round of one
     *                                     user turn spends from the same pool; when null
     *                                     (a task, a suggestion) a single-round budget is
     *                                     created, which is the old behaviour for a caller
     *                                     that makes exactly one call.
     *
     * @throws AiProviderException when no candidate can answer, or when the privacy
     *                             classification is missing (an application fault)
     */
    public function complete(
        array $messages,
        array $tools = [],
        ?CloudAiProvider $preferred = null,
        ?string $task = null,
        ?float $deadline = null,
        ?bool $requiresClientData = null,
        ?AiTurnBudget $budget = null,
    ): AiResponse {
        // The classification is mandatory. A07-R4-R1 fixed today's callers, but
        // the contract still defaulted the argument to "public", so the next
        // consumer that forgot it would send private content to a provider whose
        // free tier may train on it. An absent classification is an application
        // fault, refused here before any HTTP request, and it is terminal: no
        // other provider can make a missing classification safe.
        if ($requiresClientData === null) {
            throw AiProviderException::misclassified(
                'La llamada al proveedor no declaró la clasificación de privacidad; '
                .'se rechaza por defecto y no se envió nada.'
            );
        }

        // An explicit "public" is still only a claim about *intent*. The payload
        // itself has the final say: text that carries a sensitive signal (an
        // email, a long identifier, a phone) is promoted to private here, so a
        // caller cannot downgrade a record to public by passing `false`.
        if (! $requiresClientData && $this->payloadLooksSensitive($messages)) {
            $requiresClientData = true;
        }

        $required = $tools !== [] ? ['chat', 'tools'] : ['chat'];

        if ($requiresClientData) {
            $required[] = 'client_data';
        }

        $candidates = $this->ordered($this->pool->supporting($required), $preferred);

        // A model that refused tool calling keeps its place for conversation but
        // is not offered a tool payload again, so the same 400 is not repeated
        // every turn while it still answers general questions.
        if ($tools !== []) {
            $candidates = array_values(array_filter(
                $candidates,
                fn (ModelCandidate $candidate): bool => ! $this->health->toolsUnsupported($candidate),
            ));
        }

        if ($candidates === []) {
            if ($this->pool->all() === []) {
                throw AiProviderException::notConfigured();
            }

            // The specific case the privacy boundary creates: the payload needs a
            // provider authorised for private data and there is none right now.
            // Saying so is the honest failure — the record stays on the server.
            if ($requiresClientData) {
                throw AiProviderException::unavailable(
                    'No hay ningún proveedor autorizado para datos privados en este momento; '
                    .'la información no salió del servidor. Vuelve a intentarlo en unos minutos.'
                );
            }

            throw AiProviderException::unavailable(
                $tools !== []
                    ? 'No hay ningún modelo configurado que admita herramientas en este momento.'
                    : 'No hay ningún modelo de IA configurado y disponible.'
            );
        }

        // One budget for the whole turn. When the caller did not supply one this
        // is still bounded — it simply bounds this call.
        $budget ??= AiTurnBudget::forTurn($tools !== [], $deadline);

        // The turn's wall-clock budget. A free model can be slow, and several of
        // them in a row must still finish inside the proxy's own limit, so the
        // budget — not a fixed per-call timeout — is what bounds the turn.
        $deadline = $budget->deadline;

        $configuredTimeout = max(3, (int) config('ai.timeout_seconds', 30));
        $lastFailure = null;
        $skipped = 0;
        $skippedRateLimited = 0;
        $outOfTime = false;

        foreach ($candidates as $candidate) {
            if ($budget->exhausted()) {
                break;
            }

            $remaining = $budget->remainingSeconds();

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

            // The provider's own 429 is authoritative, but a documented RPM/RPD
            // can be respected deliberately instead of hit.
            if (! $this->quota->allow($candidate)) {
                $skipped++;
                $skippedRateLimited++;

                continue;
            }

            $budget->spend();
            $this->quota->record($candidate);
            $startedAt = microtime(true);

            // Never wait longer than the turn has left, and never longer than the
            // operator's per-attempt ceiling.
            $attemptTimeout = max(3, min($configuredTimeout, $remaining));

            try {
                $response = $this->providerFor($candidate)->complete($messages, $tools, $attemptTimeout);
            } catch (AiProviderException $e) {
                $latency = $this->latenciesFrom($startedAt);

                // A refusal that is not about this model — a content-policy
                // decision, or an application/configuration fault — ends the turn.
                // It is not the model's fault, so the model is not cooled down,
                // and it is not evaded by asking a different provider.
                if ($e->isTerminal()) {
                    $this->recordDiagnostic($task, $candidate, $e, $latency, 'refused');

                    throw $e;
                }

                // A model that rejected the tools payload is downgraded rather
                // than cooled: it can still answer conversation.
                if ($e->missingCapability === 'tools') {
                    $this->health->markToolsUnsupported($candidate);
                }

                $this->health->recordFailure($candidate, $e, $latency);
                $this->recordDiagnostic($task, $candidate, $e, $latency, 'failed');

                $lastFailure = $e;
                $budget->countFailover();

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
                $budget->countFailover();

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
        if ($budget->exhausted()) {
            throw AiProviderException::unavailable(
                'El turno agotó el número de intentos disponibles antes de que un modelo respondiera.'
            );
        }

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
     * Whether the outgoing payload itself carries a sensitive signal.
     *
     * The classification callers pass is about *provenance* (this turn is about
     * a client). This is the content floor: even a turn classified public is
     * promoted when the exact text about to be sent holds an email, a long digit
     * run or a grouped phone number. It can only raise the classification, never
     * lower it.
     *
     * @param  list<AiMessage>  $messages
     */
    private function payloadLooksSensitive(array $messages): bool
    {
        $texts = [];

        foreach ($messages as $message) {
            // The system prompt is written by the application and fixed; only
            // what the person wrote, the model said or a tool returned can carry
            // a record.
            if ($message->role === AiMessage::ROLE_SYSTEM) {
                continue;
            }

            $texts[] = $message->content;
        }

        return $this->privacy->payloadRequiresClientData(texts: $texts);
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
            // Where the fault lies and what the resolver did about it. `scope` is
            // the classified failure scope (model, credential, quota_model,
            // quota_account, capability, policy, internal); `action` says whether
            // the turn moved on or stopped, which is what turns a bare 503 into a
            // diagnosis.
            'scope' => $error?->scope,
            'account_wide' => $error?->accountWide === true ?: null,
            'action' => match (true) {
                $error === null => 'answered',
                $error->isTerminal() => 'stopped',
                default => 'failed_over',
            },
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

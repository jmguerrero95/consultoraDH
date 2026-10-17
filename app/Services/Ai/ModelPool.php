<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * The set of models this deployment may use, assembled from configuration and
 * refined by the live OpenRouter catalogue.
 *
 * ## Backward compatible by construction
 *
 * A deployment that only has `AI_PRIMARY_*` and `AI_FALLBACK_*` gets exactly two
 * candidates, in that order — the same behaviour as before there was a pool. The
 * pool is *additive*: `AI_POOL` (JSON) adds explicit candidates, `AI_EXTRA_MODELS`
 * adds free OpenRouter models, and the catalogue can append more when it is
 * cached. Nothing about the two-slot deployment changes.
 *
 * ## Free-only, where free-only means something
 *
 * The paid-model prohibition applies to OpenRouter candidates, because that is
 * the catalogue whose prices this application can actually read. A candidate on
 * OpenRouter is kept only when the catalogue says both prices are `"0"`, or —
 * when no catalogue is cached yet — when its id is a `:free` variant or the
 * `openrouter/free` router. An operator's own independent endpoint is their
 * decision and is not filtered by OpenRouter's prices.
 *
 * ## Capabilities come from the catalogue when it is known
 *
 * Tool support is not assumed: if the catalogue says a model does not accept a
 * tool catalogue, the 'tools' capability is removed so a tool-bearing question
 * routes around it instead of failing against it.
 */
class ModelPool
{
    public function __construct(private readonly ModelCatalog $catalog) {}

    /**
     * Every candidate, ordered by configured priority (lowest first).
     *
     * @return list<ModelCandidate>
     */
    public function all(): array
    {
        $openRouter = $this->openRouterEndpoint();

        $candidates = [];

        $this->push($candidates, $this->slot('primary', 10));
        $this->push($candidates, $this->slot('fallback', 20));

        foreach ($this->explicit() as $candidate) {
            $this->push($candidates, $candidate);
        }

        if ($openRouter !== null) {
            // The free router is a real candidate: OpenRouter picks a free model
            // that matches the requested capabilities, so it is not a placeholder.
            if ((bool) config('ai.openrouter.include_free_router', true)) {
                $this->push($candidates, new ModelCandidate(
                    baseUrl: $openRouter['base_url'],
                    apiKey: $openRouter['api_key'],
                    model: (string) config('ai.openrouter.free_router', 'openrouter/free'),
                    priority: (int) config('ai.openrouter.free_router_priority', 50),
                    source: 'free_router',
                ));
            }

            $extraPriority = (int) config('ai.extra_models_priority', 5);

            foreach ($this->extraModels() as $model) {
                $this->push($candidates, new ModelCandidate(
                    baseUrl: $openRouter['base_url'],
                    apiKey: $openRouter['api_key'],
                    model: $model,
                    priority: $extraPriority++,
                    source: 'extra',
                ));
            }

            if ((bool) config('ai.openrouter.dynamic_pool', true) && $this->catalog->available()) {
                $dynamicPriority = 200;
                $limit = max(0, (int) config('ai.openrouter.max_dynamic_models', 5));

                foreach ($this->catalog->freeToolCapable() as $model) {
                    if ($limit === 0) {
                        break;
                    }

                    $this->push($candidates, new ModelCandidate(
                        baseUrl: $openRouter['base_url'],
                        apiKey: $openRouter['api_key'],
                        model: $model,
                        priority: $dynamicPriority++,
                        source: 'catalog',
                        contextLength: $this->catalog->contextLength($model),
                    ));

                    $limit--;
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * Candidates that can serve a turn needing the given capabilities.
     *
     * @param  list<string>  $required
     * @return list<ModelCandidate>
     */
    public function supporting(array $required): array
    {
        return array_values(array_filter(
            $this->all(),
            function (ModelCandidate $candidate) use ($required): bool {
                foreach ($required as $capability) {
                    if ($capability === 'tools' && ! $candidate->supportsTools()) {
                        return false;
                    }

                    if ($capability === 'chat' && ! $candidate->supportsChat()) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * Apply the catalogue's verdict and drop candidates that break the free rule.
     *
     * @param  array<string, ModelCandidate>  $byKey
     */
    private function push(array &$byKey, ?ModelCandidate $candidate): void
    {
        if ($candidate === null || $candidate->model === '' || $candidate->baseUrl === '' || $candidate->apiKey === '') {
            return;
        }

        if ($candidate->isOpenRouter() && ! $this->allowedOnOpenRouter($candidate)) {
            return;
        }

        $refined = $this->refineCapabilities($candidate);

        // First declaration wins, so a model named both in a slot and in the
        // seed list keeps the better (lower) priority rather than the later one.
        if (! isset($byKey[$refined->key()])) {
            $byKey[$refined->key()] = $refined;
        }
    }

    private function allowedOnOpenRouter(ModelCandidate $candidate): bool
    {
        $free = $this->catalog->isFree($candidate->model);

        if ($free === true) {
            return true;
        }

        if ($free === false) {
            return false;
        }

        // The catalogue is cached but does not list this id: it has been retired
        // or renamed, and re-advertising it would only produce a 404 per attempt.
        if ($this->catalog->available()) {
            return false;
        }

        // No catalogue yet: trust the naming convention and nothing else.
        return str_ends_with($candidate->model, ':free')
            || $candidate->model === (string) config('ai.openrouter.free_router', 'openrouter/free');
    }

    private function refineCapabilities(ModelCandidate $candidate): ModelCandidate
    {
        if (! $candidate->isOpenRouter()) {
            return $candidate;
        }

        $tools = $this->catalog->supportsTools($candidate->model);

        if ($tools === null) {
            return $candidate;
        }

        $capabilities = $candidate->capabilities;

        if (! $tools) {
            $capabilities = array_values(array_filter($capabilities, fn (string $c): bool => $c !== 'tools'));
        } elseif (! in_array('tools', $capabilities, true)) {
            $capabilities[] = 'tools';
        }

        return new ModelCandidate(
            baseUrl: $candidate->baseUrl,
            apiKey: $candidate->apiKey,
            model: $candidate->model,
            priority: $candidate->priority,
            capabilities: $capabilities,
            source: $candidate->source,
            contextLength: $candidate->contextLength ?? $this->catalog->contextLength($candidate->model),
        );
    }

    private function slot(string $name, int $priority): ?ModelCandidate
    {
        $config = (array) config("ai.{$name}", []);

        $baseUrl = $config['base_url'] ?? null;
        $apiKey = $config['api_key'] ?? null;
        $model = $config['model'] ?? null;

        if (! is_string($baseUrl) || $baseUrl === '' || ! is_string($apiKey) || $apiKey === '' || ! is_string($model) || $model === '') {
            return null;
        }

        return new ModelCandidate(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            model: $model,
            priority: $priority,
            source: 'slot',
        );
    }

    /**
     * @return list<ModelCandidate>
     */
    private function explicit(): array
    {
        $entries = config('ai.pool', []);
        $openRouter = $this->openRouterEndpoint();
        $candidates = [];
        $priority = 30;

        if (! is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['model'] ?? null)) {
                continue;
            }

            $baseUrl = $entry['base_url'] ?? $openRouter['base_url'] ?? null;
            $apiKey = $entry['api_key'] ?? $openRouter['api_key'] ?? null;

            if (! is_string($baseUrl) || $baseUrl === '' || ! is_string($apiKey) || $apiKey === '') {
                continue;
            }

            $capabilities = $entry['capabilities'] ?? null;

            $candidates[] = new ModelCandidate(
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                model: $entry['model'],
                priority: is_int($entry['priority'] ?? null) ? $entry['priority'] : $priority++,
                capabilities: is_array($capabilities) && $capabilities !== []
                    ? array_values(array_map('strval', $capabilities))
                    : ['chat', 'tools'],
                source: 'pool',
                contextLength: is_int($entry['context'] ?? null) ? $entry['context'] : null,
            );
        }

        return $candidates;
    }

    /**
     * Explicit extra model ids, only meaningful for an OpenRouter endpoint.
     *
     * @return list<string>
     */
    private function extraModels(): array
    {
        $entries = config('ai.extra_models', []);

        if (! is_array($entries)) {
            return [];
        }

        $out = [];

        foreach ($entries as $model) {
            if (is_string($model) && trim($model) !== '') {
                $out[] = trim($model);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The OpenRouter endpoint to attach free-router / extra / dynamic models to.
     *
     * @return array{base_url: string, api_key: string}|null
     */
    private function openRouterEndpoint(): ?array
    {
        foreach (['primary', 'fallback'] as $name) {
            $config = (array) config("ai.{$name}", []);
            $baseUrl = $config['base_url'] ?? null;
            $apiKey = $config['api_key'] ?? null;

            if (is_string($baseUrl) && str_contains($baseUrl, 'openrouter.ai')
                && is_string($apiKey) && $apiKey !== '') {
                return ['base_url' => $baseUrl, 'api_key' => $apiKey];
            }
        }

        // An explicit pool entry may be the only OpenRouter credential.
        foreach ((array) config('ai.pool', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $baseUrl = $entry['base_url'] ?? null;
            $apiKey = $entry['api_key'] ?? null;

            if (is_string($baseUrl) && str_contains($baseUrl, 'openrouter.ai')
                && is_string($apiKey) && $apiKey !== '') {
                return ['base_url' => $baseUrl, 'api_key' => $apiKey];
            }
        }

        return null;
    }
}

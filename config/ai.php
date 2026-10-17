<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A07: cloud AI assistant configuration
|--------------------------------------------------------------------------
|
| Every value here is authoritative configuration reached through `config()`,
| never `env()` at the call site, so a cached configuration and a test see the
| same mapping.
|
| ## Neutral provider names
|
| The two provider slots are named for their *role*, not for a vendor. The
| implementation speaks the OpenAI-compatible chat-completions HTTP shape,
| which several hosts serve, so the operator chooses the host through
| AI_PRIMARY_BASE_URL without any code change here. No vendor name appears in
| domain code, and no key is ever stored in the database.
|
| ## What is deliberately absent
|
| No embedding model, no vector store, no retrieval index. The assistant answers
| from the application's own services through a closed tool registry, so there is
| nothing to embed and nothing to retrieve from.
|
| ## Secrets
|
| An API key is a credential and is treated as one: it is never written to the
| database, never returned by an endpoint, and never placed in audit metadata.
| `/api/ai/status` reports only whether a slot is configured and which model it
| names.
|
*/
return [
    /*
     * Master switch. AI_ENABLED=false is the default, and the assistant answers
     * 503 rather than pretending to work without a provider.
     *
     * AI_ENABLED
     */
    'enabled' => (bool) env('AI_ENABLED', false),

    /*
     * Whole-request budget. Also the timeout applied to each provider attempt.
     *
     * AI_TIMEOUT_SECONDS
     */
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 30),

    /*
     * How long a proposed write waits for a person before it can no longer be
     * confirmed. Short, because the proposal is a live intent rather than a
     * record of one.
     *
     * AI_PROPOSAL_TTL_MINUTES
     */
    'proposal_ttl_minutes' => (int) env('AI_PROPOSAL_TTL_MINUTES', 15),

    /*
     * The two provider slots.
     *
     * `required` reports whether the slot is usable: a base URL, a key and a
     * model all present. A half-configured slot is not "configured"; the status
     * endpoint says no, and the resolver skips it rather than attempting a call
     * that cannot succeed.
     */
    'primary' => [
        'base_url' => env('AI_PRIMARY_BASE_URL'),
        'api_key' => env('AI_PRIMARY_API_KEY'),
        'model' => env('AI_PRIMARY_MODEL'),
    ],

    'fallback' => [
        'base_url' => env('AI_FALLBACK_BASE_URL'),
        'api_key' => env('AI_FALLBACK_API_KEY'),
        'model' => env('AI_FALLBACK_MODEL'),
    ],

    /*
     * Failover policy.
     *
     * `max_attempts` bounds how many provider calls one turn may make. The pool
     * may hold many models, but a single question still makes at most this many
     * calls, so a broad pool cannot become an unbounded sequence of timeouts.
     *
     * `cooldown_seconds` is retained for compatibility with deployments that set
     * it; the per-model policy actually in force lives under `health` below,
     * where the cooldown grows with the failure streak instead of being fixed.
     *
     * AI_MAX_ATTEMPTS
     * AI_COOLDOWN_SECONDS
     */
    'max_attempts' => (int) env('AI_MAX_ATTEMPTS', 4),

    'cooldown_seconds' => (int) env('AI_COOLDOWN_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | The model pool (BUG-020-R2)
    |--------------------------------------------------------------------------
    |
    | The pool is every model this deployment may use. It is built from the two
    | slots above (so an existing two-slot deployment gets exactly the old two
    | candidates, in order), from `pool` here, from `extra_models`, and — for an
    | OpenRouter endpoint — from the live catalogue.
    |
    | `pool` is a JSON list of objects, each with `model` and optionally
    | `base_url`, `api_key`, `priority`, `capabilities` and `context`. When the
    | URL and key are omitted the candidate inherits the OpenRouter endpoint.
    |
    |   AI_POOL='[{"model":"nvidia/nemotron-3.5-lightning:free","priority":5}]'
    |
    | `extra_models` is a comma-separated list of OpenRouter model ids to add as
    | additional free candidates, tried after the primary and the fallback. The
    | default is a small seed of currently-free, tool-capable models; the cached
    | catalogue is authoritative, so a seed that has been retired, made paid or
    | stripped of tools is dropped automatically rather than advertised.
    |
    |   AI_EXTRA_MODELS='nvidia/nemotron-3-super-120b-a12b:free,openrouter/free'
    |
    | Both are optional. With neither set, behaviour is unchanged from before the
    | pool existed.
    */
    'pool' => json_decode((string) env('AI_POOL', '[]'), true) ?: [],

    'extra_models' => array_values(array_filter(array_map(
        static fn (string $model): string => trim($model),
        explode(',', (string) env(
            'AI_EXTRA_MODELS',
            'nvidia/nemotron-3-super-120b-a12b:free,nvidia/nemotron-3-ultra-550b-a55b:free',
        )),
    ))),

    /*
     * Priority given to the first `extra_models` entry. It is intentionally
     * lower (better) than the two slots, because the slots are whatever was
     * configured when the assistant was set up — in this deployment a Google
     * free model that answers 429 and an NVIDIA one that times out — while the
     * extras are the models that have actually been observed to answer. The live
     * health ordering refines this further; this is only the cold-start order,
     * and it is what stops a known-failing primary from spending the first
     * minute of every first question.
     */
    'extra_models_priority' => (int) env('AI_EXTRA_MODELS_PRIORITY', 5),

    /*
    |--------------------------------------------------------------------------
    | OpenRouter integration
    |--------------------------------------------------------------------------
    |
    | `dynamic_pool` lets a cached catalogue add free, tool-capable models to the
    | pool. It only ever reads the cache: a question never downloads the
    | catalogue, so a request handler never blocks on OpenRouter. `ai:models`
    | refreshes the cache.
    |
    | `use_models_fallback` turns on OpenRouter's own ordered `models` field. It
    | is off by default because the resolver already falls back candidate by
    | candidate; running both at once would duplicate requests rather than add a
    | route. Turn it on only to hand OpenRouter the intra-request choice instead.
    |
    | `include_free_router` adds `openrouter/free` as a real candidate: OpenRouter
    | selects a free model matching the requested capabilities. `openrouter/auto`
    | is deliberately never used, because the general router may pick a paid one.
    */
    'openrouter' => [
        'catalog_url' => env('AI_OPENROUTER_CATALOG_URL', 'https://openrouter.ai/api/v1/models'),
        'catalog_timeout_seconds' => (int) env('AI_OPENROUTER_CATALOG_TIMEOUT_SECONDS', 20),
        'catalog_cache_seconds' => (int) env('AI_OPENROUTER_CATALOG_CACHE_SECONDS', 21600),
        'catalog_failure_cache_seconds' => (int) env('AI_OPENROUTER_CATALOG_FAILURE_CACHE_SECONDS', 300),
        'free_router' => env('AI_OPENROUTER_FREE_ROUTER', 'openrouter/free'),
        'free_router_priority' => (int) env('AI_OPENROUTER_FREE_ROUTER_PRIORITY', 90),
        'include_free_router' => (bool) env('AI_OPENROUTER_INCLUDE_FREE_ROUTER', true),
        'dynamic_pool' => (bool) env('AI_OPENROUTER_DYNAMIC_POOL', true),
        'max_dynamic_models' => (int) env('AI_OPENROUTER_MAX_DYNAMIC_MODELS', 5),
        'use_models_fallback' => (bool) env('AI_OPENROUTER_USE_MODELS_FALLBACK', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health and progressive recovery
    |--------------------------------------------------------------------------
    |
    | Per (endpoint, model) health is kept in the cache. A failure cools the
    | model down for `base_cooldown_seconds` doubled once per consecutive failure
    | and capped at `max_cooldown_seconds`, so a single blip is retried in under a
    | minute while a model that keeps failing is set aside for longer — never
    | forever. A model that answered within `success_ttl_seconds` is preferred
    | over one that has not.
    |
    | A rejected credential disables the whole endpoint for
    | `credential_cooldown_seconds`, because switching models behind the same key
    | is the same wall. A model the provider does not know is parked for
    | `model_missing_cooldown_seconds` until the catalogue is corrected.
    */
    'health' => [
        'base_cooldown_seconds' => (int) env('AI_HEALTH_BASE_COOLDOWN_SECONDS', 45),
        'max_cooldown_seconds' => (int) env('AI_HEALTH_MAX_COOLDOWN_SECONDS', 1800),
        'model_missing_cooldown_seconds' => (int) env('AI_HEALTH_MODEL_MISSING_COOLDOWN_SECONDS', 21600),
        'credential_cooldown_seconds' => (int) env('AI_HEALTH_CREDENTIAL_COOLDOWN_SECONDS', 600),
        'success_ttl_seconds' => (int) env('AI_HEALTH_SUCCESS_TTL_SECONDS', 900),
    ],

    /*
     * Whole-turn budget, in seconds. A turn that needs several tool rounds is
     * allowed to take longer than a single provider attempt; this bounds the
     * total so a slow free model cannot hold a request open indefinitely. It is
     * deliberately below the deployment's HTTP ceiling (nginx
     * `fastcgi_read_timeout`, 180s) so the assistant fails as its own 503 with a
     * diagnosis rather than as a proxy 504 with none. It has to be large enough
     * for a tool call plus the answer that follows it: a cold first turn may
     * spend one 60s timeout on a model that then turns out to be unusable.
     *
     * AI_TOTAL_BUDGET_SECONDS
     */
    'total_budget_seconds' => (int) env('AI_TOTAL_BUDGET_SECONDS', 150),

    /*
     * How long the status endpoint's answer is cached. It reads configuration and
     * the cooldown state and calls no provider, so this is short — just enough to
     * keep a polling widget from re-computing it on every tick.
     *
     * AI_STATUS_CACHE_SECONDS
     */
    'status_cache_seconds' => (int) env('AI_STATUS_CACHE_SECONDS', 20),

    /*
     * Web search configuration (SearXNG-compatible).
     *
     * AI_WEB_SEARCH_ENABLED
     * AI_WEB_SEARCH_BASE_URL
     * AI_WEB_SEARCH_TIMEOUT_SECONDS
     */
    'web_search' => [
        'enabled' => (bool) env('AI_WEB_SEARCH_ENABLED', false),
        'base_url' => env('AI_WEB_SEARCH_BASE_URL'),
        'timeout_seconds' => (int) env('AI_WEB_SEARCH_TIMEOUT_SECONDS', 10),
    ],

    /*
     * Artifact configuration.
     *
     * AI_ARTIFACT_TTL_DAYS
     */
    'artifact_ttl_days' => (int) env('AI_ARTIFACT_TTL_DAYS', 30),

    /*
     * Temperature for model responses.
     *
     * AI_TEMPERATURE
     */
    'temperature' => (float) env('AI_TEMPERATURE', 0.2),

    /*
     | Proactive suggestions.
     |
     | `reasoning.enabled` turns on the one place a model is consulted while
     | suggesting. It is off by default here rather than on, because a
     | deployment that has not thought about its free-tier quota should get the
     | deterministic suggestions - which are always correct - and not the
     | considered ones.
     |
     | The gates themselves (relevance, evidence substance, per-problem window
     | and daily ceiling) live in `SuggestionReasoner` as constants: they are
     | rules about when interrupting someone is acceptable, not deployment
     | settings, and a deployment should not be able to raise them.
     |
     | AI_SUGGESTION_REASONING
     */
    'suggestions' => [
        'reasoning' => [
            'enabled' => (bool) env('AI_SUGGESTION_REASONING', false),
        ],
    ],

];

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
/*
 * A comma-separated environment value as a clean list. Defined here because it
 * only ever shapes this file's arrays.
 */
$csv = static function (?string $value): array {
    return array_values(array_filter(
        array_map('trim', explode(',', (string) $value)),
        static fn (string $item): bool => $item !== '',
    ));
};

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
     * `max_attempts` bounds how many provider calls one general turn may make
     * (one round of conversation). `tool_max_attempts` is the same bound for a
     * turn that uses tools, which is several rounds by nature — ask, call a tool,
     * synthesise — so it needs more room for a legitimate operation plus a couple
     * of failovers.
     *
     * Both are *per user turn*, not per provider round: the assistant service
     * creates one `AiTurnBudget` and every `complete()` call of that turn spends
     * from it. That is the BUG-021 fix — the counter used to be local to
     * `complete()`, so a three-round question had three independent budgets.
     *
     * `cooldown_seconds` is retained for compatibility with deployments that set
     * it; the per-model policy actually in force lives under `health` below,
     * where the cooldown grows with the failure streak instead of being fixed.
     *
     * AI_MAX_ATTEMPTS
     * AI_TOOL_MAX_ATTEMPTS
     * AI_COOLDOWN_SECONDS
     */
    'max_attempts' => (int) env('AI_MAX_ATTEMPTS', 4),

    'tool_max_attempts' => (int) env('AI_TOOL_MAX_ATTEMPTS', 8),

    'tool_max_rounds' => (int) env('AI_TOOL_MAX_ROUNDS', 4),

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
    | Independent free providers
    |--------------------------------------------------------------------------
    |
    | OpenRouter is not the only free tier, and depending on one shared quota is
    | the fragility this section removes. Each entry is an independent endpoint
    | with its own credential, so a quota wall on one provider does not stop the
    | assistant: the resolver moves to the next candidate, and health is tracked
    | per (endpoint, model) exactly as it is for OpenRouter.
    |
    | An entry contributes candidates only when it has a base URL, a key AND at
    | least one model id. Nothing is invented: with no key there is no candidate,
    | so the pool never advertises a provider the deployment cannot call.
    |
    | `models` is the free-only enforcement for an independent endpoint. This
    | application cannot read those providers' price lists, so the operator
    | declares what is free, exactly as the pool report documents. Listing a paid
    | model here would contradict the free-only mandate; the list is the contract.
    |
    | `data_policy` is the privacy gate. `training` means the provider's free tier
    | may use the content to train, so real client data must not be sent there:
    | such a candidate only serves general questions unless
    | `AI_ALLOW_TRAINING_PROVIDERS` is set. The default is the safe one.
    |
    | Cloudflare takes its account id in the URL, not the credential, so the
    | template carries `{account_id}` and the id comes from configuration.
    |
    | OpenCode Zen is optional and disabled unless a legitimate, separate key
    | exists and its free use is confirmed. It is never driven through the
    | OpenCode CLI or its interactive session.
    */
    'providers' => [
        'groq' => [
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'api_key' => env('GROQ_API_KEY'),
            'models' => $csv(env('GROQ_MODELS')),
            'priority' => (int) env('GROQ_PRIORITY', 150),
            'capabilities' => ['chat', 'tools'],
            'data_policy' => env('GROQ_DATA_POLICY', 'training'),
            'enabled' => (bool) env('GROQ_ENABLED', true),
        ],

        'cloudflare' => [
            'base_url' => env('CLOUDFLARE_AI_BASE_URL', 'https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/v1'),
            'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
            'api_key' => env('CLOUDFLARE_AI_API_TOKEN'),
            'models' => $csv(env('CLOUDFLARE_AI_MODELS')),
            'priority' => (int) env('CLOUDFLARE_AI_PRIORITY', 150),
            'capabilities' => ['chat', 'tools'],
            'data_policy' => env('CLOUDFLARE_AI_DATA_POLICY', 'training'),
            'enabled' => (bool) env('CLOUDFLARE_AI_ENABLED', true),
        ],

        'gemini' => [
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
            'api_key' => env('GEMINI_API_KEY'),
            'models' => $csv(env('GEMINI_MODELS')),
            'priority' => (int) env('GEMINI_PRIORITY', 150),
            'capabilities' => ['chat', 'tools'],
            'data_policy' => env('GEMINI_DATA_POLICY', 'training'),
            'enabled' => (bool) env('GEMINI_ENABLED', true),
        ],

        'opencode_zen' => [
            'base_url' => env('OPENCODE_ZEN_BASE_URL', 'https://opencode.ai/zen/v1'),
            'api_key' => env('OPENCODE_ZEN_API_KEY'),
            'models' => $csv(env('OPENCODE_ZEN_MODELS')),
            'priority' => (int) env('OPENCODE_ZEN_PRIORITY', 250),
            'capabilities' => ['chat', 'tools'],
            'data_policy' => env('OPENCODE_ZEN_DATA_POLICY', 'training'),
            'enabled' => (bool) env('OPENCODE_ZEN_ENABLED', false),
        ],
    ],

    /*
     * Whether a provider whose free tier may train on the content may receive
     * real business data. Off by default; turning it on is an explicit,
     * documented decision by the owner, not a default.
     *
     * AI_ALLOW_TRAINING_PROVIDERS
     */
    'privacy' => [
        'allow_training_providers' => (bool) env('AI_ALLOW_TRAINING_PROVIDERS', false),
    ],

    /*
     | Local request quotas per provider, in requests per minute and per day.
     |
     | Zero disables the local cap: the provider's own 429 (and `Retry-After`)
     | is then the only limit — the safe default, because a wrong local cap would
     | refuse a request the provider would have served. Set these to stay under a
     | documented free tier deliberately instead of discovering it through a 429.
     | Token quotas (TPM/TPD-like) are honoured from the provider's own headers on
     | a 429 rather than metered locally, which would be a poorer count than the
     | provider's.
     */
    'rate_limits' => [
        'openrouter' => ['rpm' => (int) env('AI_OPENROUTER_RPM', 0), 'rpd' => (int) env('AI_OPENROUTER_RPD', 0)],
        'groq' => ['rpm' => (int) env('GROQ_RPM', 0), 'rpd' => (int) env('GROQ_RPD', 0)],
        'cloudflare' => ['rpm' => (int) env('CLOUDFLARE_AI_RPM', 0), 'rpd' => (int) env('CLOUDFLARE_AI_RPD', 0)],
        'gemini' => ['rpm' => (int) env('GEMINI_RPM', 0), 'rpd' => (int) env('GEMINI_RPD', 0)],
        'opencode_zen' => ['rpm' => (int) env('OPENCODE_ZEN_RPM', 0), 'rpd' => (int) env('OPENCODE_ZEN_RPD', 0)],
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
    | is the same wall. That window *escalates* with each further rejection up to
    | `credential_max_cooldown_seconds`, so a still-denied key stops being probed
    | as a side effect of ordinary questions, while the cap — and the fact that
    | correcting the key produces a new endpoint hash, hence fresh state — keeps
    | recovery possible. A model the provider does not know is parked for
    | `model_missing_cooldown_seconds` until the catalogue is corrected.
    */
    'health' => [
        'base_cooldown_seconds' => (int) env('AI_HEALTH_BASE_COOLDOWN_SECONDS', 45),
        'max_cooldown_seconds' => (int) env('AI_HEALTH_MAX_COOLDOWN_SECONDS', 1800),
        'model_missing_cooldown_seconds' => (int) env('AI_HEALTH_MODEL_MISSING_COOLDOWN_SECONDS', 21600),
        'credential_cooldown_seconds' => (int) env('AI_HEALTH_CREDENTIAL_COOLDOWN_SECONDS', 600),
        'credential_max_cooldown_seconds' => (int) env('AI_HEALTH_CREDENTIAL_MAX_COOLDOWN_SECONDS', 86400),
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
     * Weather configuration (Open-Meteo, free and key-less).
     *
     * The harmless specialisation of "what is true today": two fixed public
     * endpoints — one to turn a place name into coordinates, one for the
     * observation and forecast. No key, no payment, and the host is never taken
     * from a tool argument.
     *
     * AI_WEATHER_ENABLED
     * AI_WEATHER_GEOCODING_URL
     * AI_WEATHER_FORECAST_URL
     * AI_WEATHER_TIMEOUT_SECONDS
     */
    'weather' => [
        'enabled' => (bool) env('AI_WEATHER_ENABLED', true),
        'geocoding_url' => env('AI_WEATHER_GEOCODING_URL', 'https://geocoding-api.open-meteo.com/v1/search'),
        'forecast_url' => env('AI_WEATHER_FORECAST_URL', 'https://api.open-meteo.com/v1/forecast'),
        'timeout_seconds' => (int) env('AI_WEATHER_TIMEOUT_SECONDS', 10),
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

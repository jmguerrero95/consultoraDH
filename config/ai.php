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
     * Failover policy (BUG-016).
     *
     * `max_attempts` bounds how many provider calls one turn may make, and
     * `cooldown_seconds` is how long a slot that failed a retryable call is left
     * alone before it is tried again. Together they keep a provider outage from
     * turning each question into a fresh timeout, without ever hiding a
     * misconfiguration: a slot that answers is cleared immediately, and a
     * rejected credential is never retried at all.
     *
     * AI_MAX_ATTEMPTS
     * AI_COOLDOWN_SECONDS
     */
    'max_attempts' => (int) env('AI_MAX_ATTEMPTS', 2),

    'cooldown_seconds' => (int) env('AI_COOLDOWN_SECONDS', 60),

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

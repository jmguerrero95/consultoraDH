<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The OpenRouter model catalogue, cached.
 *
 * ## Why this exists
 *
 * A free-only pool that is hard-coded goes stale: a model is retired, renamed to
 * a paid variant, or loses tool support, and the deployment keeps adverting it.
 * OpenRouter publishes exactly this information, so the pool is derived from its
 * catalogue rather than from a list somebody typed once.
 *
 * ## Why the request path never fetches
 *
 * `refresh()` is the only method that touches the network, and it is called by
 * the `ai:models` command — never from `AiAssistantService` or the resolver.
 * A question must not pay for a catalogue download, and a request handler must
 * not block on a third party it does not need. The resolver reads the cached
 * copy through `isFree()`, `supportsTools()` and `freeToolCapable()`, all of
 * which answer `null`/`[]` when nothing has been cached.
 *
 * ## The free rule
 *
 * A model is free when both its prompt and completion prices are exactly `"0"`.
 * OpenRouter prices are strings, so this compares strings on purpose: casting a
 * `"0"` to int and a `"0.0000001"` to int would make a paid model look free.
 */
class ModelCatalog
{
    private const CACHE_KEY = 'ai.catalog.openrouter';

    private const NEGATIVE_KEY = 'ai.catalog.openrouter.last_failure';

    public function cached(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    public function available(): bool
    {
        return $this->cached() !== null;
    }

    public function ageSeconds(): ?int
    {
        $cached = $this->cached();

        return $cached === null ? null : max(0, now()->timestamp - (int) ($cached['fetched_at'] ?? 0));
    }

    /**
     * Fetch the catalogue and cache it.
     *
     * Returns the number of models indexed, or `null` when the fetch failed. A
     * failure is cached briefly so a scheduled sync that keeps failing does not
     * hammer OpenRouter from every worker.
     */
    public function refresh(): ?int
    {
        $url = (string) config('ai.openrouter.catalog_url', 'https://openrouter.ai/api/v1/models');
        $timeout = max(3, (int) config('ai.openrouter.catalog_timeout_seconds', 20));

        try {
            $response = Http::timeout($timeout)->acceptJson()->get($url);
        } catch (Throwable) {
            $this->markFailure();

            return null;
        }

        if ($response->failed()) {
            $this->markFailure();

            return null;
        }

        $body = $response->json();
        $rows = is_array($body) ? ($body['data'] ?? null) : null;

        if (! is_array($rows)) {
            $this->markFailure();

            return null;
        }

        $models = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                continue;
            }

            $pricing = is_array($row['pricing'] ?? null) ? $row['pricing'] : [];
            $parameters = is_array($row['supported_parameters'] ?? null) ? $row['supported_parameters'] : [];

            $models[$row['id']] = [
                'free' => $this->isZeroPrice($pricing['prompt'] ?? null)
                    && $this->isZeroPrice($pricing['completion'] ?? null),
                'tools' => in_array('tools', $parameters, true),
                'context' => is_int($row['context_length'] ?? null) ? $row['context_length'] : null,
            ];
        }

        if ($models === []) {
            $this->markFailure();

            return null;
        }

        $ttl = max(60, (int) config('ai.openrouter.catalog_cache_seconds', 21600));

        Cache::put(self::CACHE_KEY, [
            'fetched_at' => now()->timestamp,
            'models' => $models,
        ], $ttl);

        Cache::forget(self::NEGATIVE_KEY);

        return count($models);
    }

    public function isFree(string $id): ?bool
    {
        $entry = $this->entry($id);

        return $entry === null ? null : (bool) $entry['free'];
    }

    public function supportsTools(string $id): ?bool
    {
        $entry = $this->entry($id);

        return $entry === null ? null : (bool) $entry['tools'];
    }

    public function contextLength(string $id): ?int
    {
        $entry = $this->entry($id);

        return $entry === null ? null : ($entry['context'] ?? null);
    }

    /**
     * Free models that advertise tool calling, newest catalogue order preserved.
     *
     * @return list<string>
     */
    public function freeToolCapable(): array
    {
        $cached = $this->cached();

        if ($cached === null) {
            return [];
        }

        $ids = [];

        foreach (($cached['models'] ?? []) as $id => $entry) {
            if (is_array($entry) && ($entry['free'] ?? false) === true && ($entry['tools'] ?? false) === true) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * @return array{free: bool, tools: bool, context: ?int}|null
     */
    private function entry(string $id): ?array
    {
        $cached = $this->cached();
        $entry = $cached['models'][$id] ?? null;

        return is_array($entry) ? $entry : null;
    }

    private function isZeroPrice(mixed $value): bool
    {
        return is_string($value) && trim($value) === '0';
    }

    private function markFailure(): void
    {
        Cache::put(self::NEGATIVE_KEY, now()->timestamp, max(60, (int) config('ai.openrouter.catalog_failure_cache_seconds', 300)));
    }
}

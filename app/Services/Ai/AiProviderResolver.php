<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiMessage;
use App\Domain\Ai\AiResponse;
use App\Domain\Ai\AiToolDefinition;
use Illuminate\Support\Facades\Cache;

/**
 * Chooses which provider answers, in what order, and how often to try again.
 *
 * ## An ordered, bounded list
 *
 * The candidates are the configured slots, in order: primary first, then the
 * fallback. There is no scoring and no round robin — the operator says which host
 * is expected to answer and which one covers for it, and that is the whole policy.
 * What the resolver adds is a bound: one turn makes at most
 * `ai.max_attempts` provider calls, so a provider that is down cannot turn a
 * single question into an unbounded sequence of timeouts.
 *
 * ## Cooldown: a provider that just failed is left alone for a while
 *
 * When a slot fails a **retryable** call — a timeout, a transport error, a 429 or
 * a 5xx — it is put in a short cooldown (`ai.cooldown_seconds`). The next turn
 * skips it and goes straight to the next candidate. This is what keeps a burst of
 * questions from each paying the full timeout against a host that is already
 * known to be failing, and it is why the status endpoint can say, truthfully,
 * which provider is answering right now. A successful call clears the cooldown at
 * once; the slot is not "punished" beyond the moment it actually fails.
 *
 * ## A rejected credential is not an outage
 *
 * A 401 or a request the provider called malformed is **not** retried against the
 * fallback and does **not** enter cooldown. The fallback has its own credential, so
 * trying it would replace a clear "this key is wrong" with a second attempt that is
 * also going to fail, and would hide the misconfiguration behind another provider's
 * answer. Those failures propagate as they are.
 *
 * The cooldown is kept in the application cache, so it is shared across the
 * workers that answer requests rather than living in one process's memory.
 */
class AiProviderResolver
{
    /**
     * @param  list<AiToolDefinition>  $tools
     * @param  list<AiMessage>  $messages
     *
     * @throws AiProviderException when no candidate can answer
     */
    public function complete(array $messages, array $tools = [], ?CloudAiProvider $preferred = null): AiResponse
    {
        $candidates = $this->candidates($preferred);

        if ($candidates === []) {
            throw AiProviderException::notConfigured();
        }

        $maxAttempts = max(1, (int) config('ai.max_attempts', count($candidates)));
        $attempts = 0;
        $lastFailure = null;
        $skippedForCooldown = 0;

        foreach ($candidates as $provider) {
            if ($attempts >= $maxAttempts) {
                break;
            }

            // A slot that failed a moment ago is skipped rather than tried again,
            // which is the whole point of the cooldown.
            if ($this->inCooldown($provider->name())) {
                $skippedForCooldown++;

                continue;
            }

            $attempts++;

            try {
                $response = $provider->complete($messages, $tools);

                // It answered, so whatever cooldown it carried is over.
                $this->clearCooldown($provider->name());

                return $response;
            } catch (AiProviderException $e) {
                $lastFailure = $e;

                // A wrong credential or a malformed request is not a provider
                // outage: it will fail the same way on the next try, and trying
                // the fallback would mask a misconfiguration.
                if (! $e->retryable) {
                    throw $e;
                }

                $this->markCooldown($provider->name());
            }
        }

        if ($lastFailure !== null) {
            throw $lastFailure;
        }

        // Every candidate was in cooldown and none was attempted. Reported as a
        // temporary unavailability, not as a configuration problem: the slots are
        // configured, they are just cooling down from a recent failure.
        throw AiProviderException::unavailable(
            'Todos los proveedores de IA están temporalmente en espera tras fallos recientes'
            .($skippedForCooldown > 0 ? " ({$skippedForCooldown} en espera)." : '.')
        );
    }

    public function primary(): CloudAiProvider
    {
        return new OpenAiCompatibleProvider($this->slot('primary'), 'primary');
    }

    public function fallback(): CloudAiProvider
    {
        return new OpenAiCompatibleProvider($this->slot('fallback'), 'fallback');
    }

    /**
     * Whether the assistant can answer at all.
     */
    public function anyConfigured(): bool
    {
        return $this->primary()->isConfigured() || $this->fallback()->isConfigured();
    }

    /**
     * Which provider would answer a question asked right now (BUG-017).
     *
     * `none`        — nothing is configured.
     * `available`   — the primary is configured and not cooling down.
     * `fallback`    — the primary is unusable right now and the fallback is not.
     * `unavailable` — providers exist but all of them are cooling down.
     *
     * No provider is contacted to answer this: it is read from configuration and
     * the cooldown cache, so asking the question costs nothing.
     */
    public function state(): string
    {
        $primary = $this->primary();
        $fallback = $this->fallback();

        if (! $primary->isConfigured() && ! $fallback->isConfigured()) {
            return 'none';
        }

        $primaryUsable = $primary->isConfigured() && ! $this->inCooldown($primary->name());
        $fallbackUsable = $fallback->isConfigured() && ! $this->inCooldown($fallback->name());

        if ($primaryUsable) {
            return 'available';
        }

        if ($fallbackUsable) {
            return 'fallback';
        }

        return 'unavailable';
    }

    /**
     * The model that would answer now, or null when none can.
     */
    public function activeModel(): ?string
    {
        $primary = $this->primary();
        $fallback = $this->fallback();

        if ($primary->isConfigured() && ! $this->inCooldown($primary->name())) {
            return $primary->model();
        }

        if ($fallback->isConfigured() && ! $this->inCooldown($fallback->name())) {
            return $fallback->model();
        }

        return null;
    }

    /**
     * How many seconds each slot is still cooling down for, keyed by slot name.
     *
     * The stored value is cast rather than checked with `is_int`: the cache store
     * configured here is Redis, and Redis returns every value as a string, so an
     * `is_int()` test was always false and the cooldown was silently never
     * applied — the mechanism was there and had no effect.
     *
     * @return array<string, int>
     */
    public function cooldowns(): array
    {
        $remaining = [];
        $now = now()->timestamp;

        foreach (['primary', 'fallback'] as $name) {
            $until = (int) Cache::get($this->cooldownKey($name), 0);

            if ($until > $now) {
                $remaining[$name] = $until - $now;
            }
        }

        return $remaining;
    }

    /**
     * The ordered, deduplicated list of providers this turn may use.
     *
     * @return list<CloudAiProvider>
     */
    private function candidates(?CloudAiProvider $preferred): array
    {
        $primary = $preferred ?? $this->primary();
        $fallback = $this->fallback();

        $list = [];
        $seen = [];

        foreach ([$primary, $fallback] as $provider) {
            $name = $provider->name();

            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;

            if ($provider->isConfigured()) {
                $list[] = $provider;
            }
        }

        return $list;
    }

    private function cooldownKey(string $name): string
    {
        return "ai.provider.cooldown.{$name}";
    }

    private function inCooldown(string $name): bool
    {
        // Cast, not `is_int`: Redis hands back a string. See `cooldowns()`.
        $until = (int) Cache::get($this->cooldownKey($name), 0);

        return $until > now()->timestamp;
    }

    private function markCooldown(string $name): void
    {
        $seconds = max(1, (int) config('ai.cooldown_seconds', 60));

        Cache::put($this->cooldownKey($name), now()->timestamp + $seconds, $seconds);
    }

    private function clearCooldown(string $name): void
    {
        Cache::forget($this->cooldownKey($name));
    }

    /**
     * @return array{base_url: ?string, api_key: ?string, model: ?string}
     */
    private function slot(string $name): array
    {
        return (array) config("ai.{$name}", ['base_url' => null, 'api_key' => null, 'model' => null]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiMessage;
use App\Domain\Ai\AiResponse;
use App\Domain\Ai\AiToolDefinition;

/**
 * Chooses which provider answers, and whether to try the other one.
 *
 * ## One primary, one fallback, tried once
 *
 * There is no scoring, no weighting and no round robin. The operator configures
 * which host is expected to answer and which one covers for it; that is the whole
 * policy, and anything more would be machinery without a reason behind it.
 *
 * ## The fallback runs exactly once, and only for the right failures
 *
 * A transport failure, a timeout or a 429 moves to the fallback, because the same
 * request may well succeed there or a moment later. A rejected credential or a
 * request the provider called malformed does not: the fallback has its own
 * credential, so retrying there would replace a clear "this key is wrong" with a
 * second attempt that is also going to fail, and would hide the misconfiguration
 * behind an unrelated provider's answer.
 */
class AiProviderResolver
{
    /**
     * @param  list<AiToolDefinition>  $tools
     * @param  list<AiMessage>  $messages
     *
     * @throws AiProviderException when no slot can answer
     */
    public function complete(array $messages, array $tools = [], ?CloudAiProvider $preferred = null): AiResponse
    {
        $primary = $preferred ?? $this->primary();
        $fallback = $this->fallback();

        if (! $primary->isConfigured() && ! $fallback->isConfigured()) {
            throw AiProviderException::notConfigured();
        }

        // A configured fallback alone is enough to answer: a deployment may
        // deliberately ship with only the covering provider configured.
        if (! $primary->isConfigured()) {
            return $fallback->complete($messages, $tools);
        }

        try {
            return $primary->complete($messages, $tools);
        } catch (AiProviderException $e) {
            if (! $e->retryable || ! $fallback->isConfigured()) {
                throw $e;
            }

            // Once. A second failure is reported as-is rather than retried again,
            // so a provider outage cannot turn one question into many calls.
            return $fallback->complete($messages, $tools);
        }
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
     * @return array{base_url: ?string, api_key: ?string, model: ?string}
     */
    private function slot(string $name): array
    {
        return (array) config("ai.{$name}", ['base_url' => null, 'api_key' => null, 'model' => null]);
    }
}

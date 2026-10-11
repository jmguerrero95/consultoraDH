<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiMessage;
use App\Domain\Ai\AiResponse;
use App\Domain\Ai\AiToolCall;
use App\Domain\Ai\AiToolDefinition;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A provider speaking the OpenAI-compatible chat-completions shape.
 *
 * ## Why one implementation covers many hosts
 *
 * Several hosted model services expose this same HTTP shape, so which one answers
 * is a matter of `AI_PRIMARY_BASE_URL` and nothing else. No vendor is named here,
 * and swapping hosts is a configuration change rather than a code change.
 *
 * ## The credential
 *
 * The API key is read from configuration per request and sent in the
 * `Authorization` header. It is never logged, never stored, never included in an
 * exception message and never returned: a failure message carries the status and
 * the provider's own error text at most, and the request headers are never part of
 * what a caller can see.
 */
class OpenAiCompatibleProvider implements CloudAiProvider
{
    /**
     * @param  array{base_url: ?string, api_key: ?string, model: ?string}  $config
     * @param  string  $name  the slot this provider fills, for audit purposes
     */
    public function __construct(
        private readonly array $config,
        private readonly string $name = 'primary',
    ) {}

    /**
     * Whether this slot can be used at all.
     *
     * All three parts are required. A half-configured slot is not "configured":
     * calling it could not succeed, and reporting otherwise would put a provider
     * that cannot answer into the resolver's rotation.
     */
    public function isConfigured(): bool
    {
        return $this->slot()['base_url'] !== null
            && $this->slot()['base_url'] !== ''
            && $this->slot()['api_key'] !== null
            && $this->slot()['api_key'] !== ''
            && $this->slot()['model'] !== null
            && $this->slot()['model'] !== '';
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The model identifier, which is safe to publish: it names the model and
     * carries no credential.
     */
    public function model(): ?string
    {
        return $this->slot()['model'];
    }

    public function toolDefinition(string $name, string $description, array $parameters = []): AiToolDefinition
    {
        return new AiToolDefinition($name, $description, $parameters);
    }

    public function complete(array $messages, array $tools = []): AiResponse
    {
        if (! $this->isConfigured()) {
            throw AiProviderException::notConfigured();
        }

        $payload = [
            'model' => $this->slot()['model'],
            'messages' => array_map(fn (AiMessage $message): array => $message->toWire(), $messages),
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(fn (AiToolDefinition $tool): array => $tool->toWire(), $tools);
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = Http::withToken($this->slot()['api_key'])
                ->timeout((int) config('ai.timeout_seconds', 30))
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint(), $payload);
        } catch (ConnectionException $e) {
            // A refused connection or a timeout: transport, so the fallback may
            // be tried. The message deliberately omits the URL and the key.
            throw AiProviderException::unavailable('The AI provider could not be reached.');
        }

        if ($response->failed()) {
            throw $this->classify($response->status(), $response->json());
        }

        return $this->parse($response->json());
    }

    /**
     * Turn a non-2xx answer into the right kind of failure.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function classify(int $status, ?array $body): AiProviderException
    {
        $detail = $this->providerMessage($body);

        return match (true) {
            $status === 401 || $status === 403 => AiProviderException::authentication(
                'The AI provider rejected the configured credentials.'.$detail
            ),

            $status === 429 => AiProviderException::rateLimited(
                'The AI provider is rate limiting this deployment.'.$detail
            ),

            $status === 400 || $status === 422 => AiProviderException::malformed(
                'The AI provider refused the request as malformed.'.$detail
            ),

            // 5xx and anything else unexpected are treated as transport: the same
            // request may well succeed on another host or in a moment.
            default => AiProviderException::unavailable(
                'The AI provider returned an error.'.$detail
            ),
        };
    }

    /**
     * The provider's own error text, if it sent one.
     *
     * Read from the error object only, and truncated: the body is provider
     * output, and an unbounded echo of it into an exception is how a large or
     * hostile payload ends up in a log.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function providerMessage(?array $body): string
    {
        $message = $body['error']['message'] ?? $body['message'] ?? null;

        if (! is_string($message) || $message === '') {
            return '';
        }

        return ' ('.mb_substr($message, 0, 200).')';
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function parse(?array $body): AiResponse
    {
        $choice = $body['choices'][0] ?? null;

        if (! is_array($choice)) {
            // A 200 with nothing usable in it is a provider fault, not a model
            // decision, and it is worth trying the other host.
            throw AiProviderException::unavailable('The AI provider returned an empty answer.');
        }

        $message = $choice['message'] ?? [];
        $content = is_string($message['content'] ?? null) ? $message['content'] : '';

        $calls = [];

        foreach ($message['tool_calls'] ?? [] as $call) {
            $function = $call['function'] ?? [];

            if (! is_string($function['name'] ?? null)) {
                continue;
            }

            $calls[] = new AiToolCall(
                $function['name'],
                $this->decodeArguments($function['arguments'] ?? null),
                is_string($call['id'] ?? null) ? $call['id'] : null,
            );
        }

        return new AiResponse(
            $content,
            $calls,
            $this->name,
            $this->slot()['model'],
            is_string($body['id'] ?? null) ? $body['id'] : null,
        );
    }

    /**
     * Tool arguments arrive as a JSON string; an unreadable one is empty rather
     * than an exception, so a malformed call is refused by the registry instead of
     * taking the turn down.
     */
    private function decodeArguments(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The chat-completions path, built from a configurable base URL.
     */
    private function endpoint(): string
    {
        return rtrim((string) $this->slot()['base_url'], '/').'/chat/completions';
    }

    /**
     * @return array{base_url: ?string, api_key: ?string, model: ?string}
     */
    private function slot(): array
    {
        return $this->config;
    }
}

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
     * @param  list<string>  $models  ordered OpenRouter fallback list, if enabled
     */
    public function __construct(
        private readonly array $config,
        private readonly string $name = 'primary',
        private readonly array $models = [],
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

    public function complete(array $messages, array $tools = [], ?int $timeoutSeconds = null): AiResponse
    {
        if (! $this->isConfigured()) {
            throw AiProviderException::notConfigured();
        }

        $timeout = max(3, $timeoutSeconds ?? (int) config('ai.timeout_seconds', 30));

        $payload = [
            'model' => $this->slot()['model'],
            'messages' => array_map(fn (AiMessage $message): array => $message->toWire(), $messages),
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(fn (AiToolDefinition $tool): array => $tool->toWire(), $tools);
            $payload['tool_choice'] = 'auto';
        }

        // OpenRouter's own ordering of alternative models for one call. It is
        // only populated when the operator explicitly asks for it, because the
        // resolver already falls back candidate by candidate and running both
        // mechanisms at once would duplicate requests rather than add a route.
        if ($this->models !== []) {
            $payload['models'] = array_values($this->models);
        }

        try {
            $response = Http::withToken($this->slot()['api_key'])
                ->timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint(), $payload);
        } catch (ConnectionException $e) {
            // A refused connection or a timeout: transport, so the fallback may
            // be tried. The message deliberately omits the URL and the key.
            throw AiProviderException::unavailable('The AI provider could not be reached.');
        }

        if ($response->failed()) {
            throw $this->classify($response->status(), $response->json(), $response->headers());
        }

        return $this->parse($response->json());
    }

    /**
     * Turn a non-2xx answer into the right kind of failure.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, array<int, string>>  $headers
     */
    private function classify(int $status, ?array $body, array $headers = []): AiProviderException
    {
        $detail = $this->providerMessage($body);

        return match (true) {
            // A safety refusal is the provider's decision about the *content*,
            // not a fault of the model or the key. It is terminal: asking a
            // different provider the same question to get the refusal answered is
            // the evasion the privacy rules forbid, so the turn stops here and
            // says why. Checked before 401/403 so a content filter expressed as a
            // 403 is not mistaken for a credential problem.
            in_array($status, [400, 403, 422], true) && $this->messageMentionsContentPolicy($body) => AiProviderException::contentPolicy(
                'The AI provider refused the content under its safety policy.'.$detail
            ),

            $status === 401 || $status === 403 => AiProviderException::authentication(
                'The AI provider rejected the configured credentials.'.$detail
            ),

            $status === 429 => AiProviderException::rateLimited(
                'The AI provider is rate limiting this deployment.'.$detail,
                $this->retryAfterSeconds($headers),
                $this->looksAccountWide($body),
                $this->looksDailyLimit($body),
            ),

            // A model the host does not know is not an outage: it is a catalogue
            // or configuration error, parked for hours rather than retried in a
            // minute. Another candidate may be perfectly healthy.
            $status === 404 => AiProviderException::modelMissing(
                'The AI provider does not recognise the configured model.'.$detail
            ),

            // Failover across candidates: free models differ in which request
            // parameters they accept. When the refusal names tools, the model is
            // not merely malformed — it cannot do tool work — so it is downgraded
            // to conversation-only rather than offered the same payload again.
            $status === 400 || $status === 422 => AiProviderException::malformed(
                'The AI provider refused the request as malformed.'.$detail,
                $this->messageMentionsTools($body) ? 'tools' : null,
            ),

            // A retired model does not always arrive as a 404: Cloudflare reports
            // a deprecated model as a 503. Treating that as a transport blip would
            // re-ask the same dead model on every turn, so it is parked like any
            // other missing model while the other candidates carry on.
            $status >= 500 && $this->messageMentionsRetiredModel($body) => AiProviderException::modelMissing(
                'The AI provider no longer serves the configured model.'.$detail
            ),

            // 5xx and anything else unexpected are treated as transport: the same
            // request may well succeed on another host or in a moment.
            default => AiProviderException::unavailable(
                'The AI provider returned an error.'.$detail
            ),
        };
    }

    /**
     * The wait the provider asked for, from whichever of its headers carries it.
     *
     * Free tiers express this differently: `Retry-After` is the standard, Groq
     * also sends `x-ratelimit-reset-requests` / `x-ratelimit-reset-tokens` as a
     * Go-style duration (`2.4s`, `1m30s`), and a value is only meaningful when it
     * can be read; anything else is ignored rather than guessed. The longest of
     * the present values wins, because the request should not be retried until
     * all of the provider's limits have cleared.
     *
     * @param  array<string, array<int, string>>  $headers
     */
    private function retryAfterSeconds(array $headers): ?int
    {
        $waits = [];

        foreach (['retry-after', 'x-ratelimit-reset-requests', 'x-ratelimit-reset-tokens'] as $name) {
            $value = $headers[$name][0] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $seconds = $this->parseWait($value);

            if ($seconds !== null) {
                $waits[] = $seconds;
            }
        }

        return $waits === [] ? null : max($waits);
    }

    /**
     * A header value that is a number of seconds, a Go-style duration, or an
     * HTTP date. Anything else is `null` rather than a guess.
     */
    private function parseWait(string $value): ?int
    {
        $value = trim($value);

        if (ctype_digit($value)) {
            return max(0, (int) $value);
        }

        // A Go-style duration: `1.5s`, `200ms`, `1m30s`, `1h2m3s`.
        if (preg_match('/^(?:\d+(?:\.\d+)?(?:h|ms|m|s))+$/', $value) === 1) {
            $seconds = 0.0;

            preg_match_all('/(\d+(?:\.\d+)?)(h|ms|m|s)/', $value, $parts, PREG_SET_ORDER);

            foreach ($parts as $part) {
                $amount = (float) $part[1];
                $seconds += match ($part[2]) {
                    'h' => $amount * 3600,
                    'm' => $amount * 60,
                    'ms' => $amount / 1000,
                    default => $amount,
                };
            }

            return (int) ceil($seconds);
        }

        // An HTTP date, e.g. "Wed, 21 Oct 2026 07:28:00 GMT".
        if (preg_match('/[A-Za-z]{3}/', $value) === 1) {
            $timestamp = strtotime($value);

            if ($timestamp !== false) {
                return max(0, $timestamp - now()->timestamp);
            }
        }

        return null;
    }

    /**
     * Whether a 429 belongs to the whole account rather than to one model.
     *
     * OpenRouter's free tier has limits that apply across every free model
     * (`free-models-per-day`, `free-models-per-min`), and those are the ones
     * where choosing another model of the same account cannot help. A 429 that
     * names only a provider or a model is scoped to that candidate and the next
     * one is tried. The test is on the provider's own words, not on the status
     * code, because the status code is identical in both cases.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function looksAccountWide(?array $body): bool
    {
        // The independent free providers (Groq, Cloudflare, Gemini, OpenCode Zen)
        // meter a *free plan*, and that plan belongs to the account, not to a
        // model: a 429 there means the deployment is walled for the window,
        // whichever model it would ask next. Trying a sibling model of the same
        // account would spend another request to learn the same thing.
        $host = parse_url((string) ($this->slot()['base_url'] ?? ''), PHP_URL_HOST) ?: '';

        foreach (['api.groq.com', 'api.cloudflare.com', 'generativelanguage.googleapis.com', 'opencode.ai'] as $named) {
            if ($host === $named || str_ends_with($host, '.'.$named)) {
                return true;
            }
        }

        $error = $body['error'] ?? null;

        if (is_string($error)) {
            $text = $error;
        } elseif (is_array($error) && is_string($error['message'] ?? null)) {
            $text = $error['message'];
        } elseif (is_string($body['message'] ?? null)) {
            $text = $body['message'];
        } else {
            return false;
        }

        $text = mb_strtolower($text);

        foreach (['free-models', 'free model', 'free models', 'daily limit', 'insufficient credits', 'requires more credits', 'add credits'] as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a 429 is a *daily* account limit rather than a per-minute one.
     *
     * The distinction is the whole difference between a wall that clears in
     * seconds and one that clears tomorrow: OpenRouter's `free-models-per-day`
     * cannot be escaped by switching to another free model of the same account,
     * and re-probing it after the ordinary cooldown only spends a request to
     * learn the same thing. The test is on the provider's own wording.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function looksDailyLimit(?array $body): bool
    {
        $text = mb_strtolower($this->providerMessage($body));

        foreach (['per-day', 'per day', 'daily limit', 'daily_limit', 'free-models-per-day', 'requests per day'] as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
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
        // Cloudflare reports failures as `{"success":false,"errors":[{"code":…,
        // "message":…}]}` rather than OpenAI's `error` object; Cloudflare's own
        // message also names the quota code, which is the useful part.
        $message = $body['error']['message'] ?? $body['message'] ?? $body['errors'][0]['message'] ?? null;

        // OpenRouter wraps an upstream refusal: the top-level message is generic
        // ("Provider returned error") and the actual reason is a JSON string
        // inside metadata.raw. Reading it is what turns a bare 400 into something
        // that names the offending field.
        $raw = $body['error']['metadata']['raw'] ?? null;

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded) && is_string($decoded['message'] ?? null)) {
                $message = $decoded['message'];
            }
        }

        if (! is_string($message) || $message === '') {
            return '';
        }

        return ' ('.mb_substr($message, 0, 200).')';
    }

    /**
     * Whether a failure body says the model itself is gone.
     *
     * Only consulted for a 5xx. It demands the word "model" *and* a retirement
     * phrase, so a generic upstream "not found" blip is not mistaken for a
     * retired model and does not park a healthy candidate for hours.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function messageMentionsRetiredModel(?array $body): bool
    {
        $text = mb_strtolower($this->providerMessage($body));

        if (! str_contains($text, 'model')) {
            return false;
        }

        foreach (['deprecated', 'does not exist', 'no longer available', 'unknown model', 'has been removed', 'not supported anymore'] as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a failure body is the provider refusing the *content*.
     *
     * Deliberately narrow: it must name a safety mechanism, so an access denial
     * expressed as a 403 ("Access denied. Please check your network settings")
     * is not misread as a content refusal and does not end the turn. Only the
     * provider's own error text is read, never the prompt.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function messageMentionsContentPolicy(?array $body): bool
    {
        $text = mb_strtolower($this->providerMessage($body));

        foreach ([
            'content policy',
            'content_policy',
            'content filter',
            'safety',
            'moderation',
            'prohibited',
            'flagged',
            'violates',
            'violation',
        ] as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a 400/422 names tool or function support as the problem.
     *
     * Used to downgrade a model that cannot do tool calling to conversation-only,
     * so it keeps answering general questions instead of being asked the same
     * rejected payload on every turn. It requires both a tool word and a
     * rejection word, so an unrelated "invalid messages[0]" is not mistaken for a
     * missing capability.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function messageMentionsTools(?array $body): bool
    {
        $text = mb_strtolower($this->providerMessage($body));

        if (! str_contains($text, 'tool') && ! str_contains($text, 'function')) {
            return false;
        }

        foreach ([
            'not supported',
            'unsupported',
            'does not support',
            "doesn't support",
            'do not support',
            'unknown',
            'unrecognized',
            'unrecognised',
            'invalid',
            'not available',
            'no tool',
        ] as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function parse(?array $body): AiResponse
    {
        $choice = $body['choices'][0] ?? null;

        if (! is_array($choice)) {
            // A 200 with nothing usable in it is a provider fault, not a model
            // decision, and it is worth trying another model.
            throw AiProviderException::emptyAnswer('The AI provider returned an empty answer.');
        }

        // A provider can also refuse the content with a 200 and a finish reason
        // rather than an error status. Treating that as an empty answer would hop
        // providers to get the same policy refusal answered elsewhere, which is
        // exactly what must not happen.
        if ($this->finishReasonIsContentFilter($choice['finish_reason'] ?? null)) {
            throw AiProviderException::contentPolicy(
                'The AI provider refused the content under its safety policy.'
            );
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
                $this->normaliseToolCallId($call['id'] ?? null),
            );
        }

        // BUG-018: a 200 with neither prose nor a tool call is not an answer.
        //
        // It used to become an `AiResponse` with `content: ''`, which the service
        // then persisted as an assistant turn: the person saw a blank bubble, and
        // the next turn replayed that blank as something the assistant had said.
        // Treating it as a retryable provider fault gives the fallback its chance
        // and, if that fails too, surfaces an error the caller can show instead of
        // storing nothing as though it were something.
        if (trim($content) === '' && $calls === []) {
            throw AiProviderException::emptyAnswer();
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
     * Whether a choice's `finish_reason` says the content was filtered.
     */
    private function finishReasonIsContentFilter(mixed $reason): bool
    {
        if (! is_string($reason)) {
            return false;
        }

        return in_array(mb_strtolower($reason), ['content_filter', 'safety', 'blocked', 'recitation'], true);
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
     * A tool call needs an id, and the assistant message that announces it and the
     * tool message that answers it must use the *same* one, or the provider
     * rejects the continuation with a 400.
     *
     * Not every model sends one. Several free models return a `tool_calls` entry
     * with no `id`, and passing that omission straight through produced
     * `tool_call_id: null` on the next round — a 400 on every candidate, because
     * the payload, not the model, was wrong. Assigning an id here, once, means
     * both messages agree on it, and the id is stable for the life of the call.
     */
    private function normaliseToolCallId(mixed $id): string
    {
        if (is_string($id) && trim($id) !== '') {
            return $id;
        }

        return 'call_'.bin2hex(random_bytes(9));
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

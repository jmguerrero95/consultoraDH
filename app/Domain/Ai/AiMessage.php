<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * One turn as the assistant sees it.
 *
 * Four roles, and the distinction between the last two is the whole
 * prompt-injection boundary:
 *
 *   system   instructions this application writes, once, before anything else
 *   user     what the person typed
 *   assistant what the model said (may include tool_calls)
 *   tool     the structured result of a call the server authorized
 *
 * A `tool` message is a quotation of a result, not an instruction. Business text
 * can carry "ignore previous instructions" inside it, and typing it here is what
 * keeps that sentence data: it arrives in the tool-result position, after the
 * instructions it is not allowed to displace.
 */
final readonly class AiMessage
{
    public const ROLE_SYSTEM = 'system';

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    /**
     * Provenance: the turn's own text — what the person typed, what the model
     * said, or the private context the turn retrieved. Shape-based checks (an
     * email, a long identifier run) apply.
     */
    public const PROVENANCE_TURN = 'turn';

    /**
     * Provenance: a public web block the server fetched for the turn.
     *
     * Its provenance is public, so a long number inside a search result — an
     * article id, a URL fragment, a statistic — is not one of our records and
     * must not, by itself, promote the turn to private (A07-R5-R1).
     */
    public const PROVENANCE_PUBLIC_WEB = 'public_web';

    /**
     * @param  array<string, mixed>|null  $toolCalls
     */
    private function __construct(
        public string $role,
        public string $content,
        /** Set when this message is the result of a tool call. */
        public ?string $toolName = null,
        /** Set when this message answers a specific call. */
        public ?string $toolCallId = null,
        /** Set when the assistant wants to call tools. */
        public ?array $toolCalls = null,
        /** Where this text came from, for provenance-aware privacy checks. */
        public string $provenance = self::PROVENANCE_TURN,
    ) {}

    public static function system(string $content): self
    {
        return new self(self::ROLE_SYSTEM, $content);
    }

    public static function user(string $content): self
    {
        return new self(self::ROLE_USER, $content);
    }

    /**
     * A public web block the server fetched for the turn.
     *
     * Delivered in the user position so the model reads it as material about the
     * question, but tagged with public provenance so a privacy check does not
     * mistake a number in a public article for a record of ours.
     */
    public static function publicWebContext(string $content): self
    {
        return new self(self::ROLE_USER, $content, provenance: self::PROVENANCE_PUBLIC_WEB);
    }

    /**
     * Create an assistant message, optionally with tool calls.
     *
     * @param  array<string, mixed>|null  $toolCalls
     */
    public static function assistant(string $content, ?array $toolCalls = null, ?string $toolCallId = null): self
    {
        return new self(self::ROLE_ASSISTANT, $content, null, $toolCallId, $toolCalls);
    }

    /**
     * A tool result, as data.
     *
     * Serialised as JSON so the model can see the shape rather than being handed
     * prose to interpret.
     *
     * @param  array<string, mixed>  $result
     */
    public static function toolResult(string $toolName, array $result, string $toolCallId): self
    {
        return new self(
            self::ROLE_TOOL,
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $toolName,
            $toolCallId,
        );
    }

    /**
     * The wire shape an OpenAI-compatible endpoint expects.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $wire = ['role' => $this->role, 'content' => $this->content];

        if ($this->role === self::ROLE_ASSISTANT && $this->toolCalls !== null) {
            $wire['tool_calls'] = array_map(function (array $call): array {
                // `arguments` must be a JSON *object* string, not a JSON array.
                // A call with no arguments decodes to `[]`, which encoded as
                // `"[]"` and was rejected by the provider — with the same 400 for
                // every candidate, because the payload, not the model, was wrong.
                // Casting the top level to an object makes `[]` become `{}` while
                // nested arrays stay arrays. Invalid UTF-8 is substituted rather
                // than making json_encode return `false`, which every provider
                // reports as "failed to parse tool call arguments".
                $arguments = json_encode(
                    (object) ($call['arguments'] ?? []),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                );

                $wireCall = [
                    'id' => $call['id'] ?? '',
                    'type' => 'function',
                    'function' => [
                        'name' => $call['name'],
                        'arguments' => $arguments === false ? '{}' : $arguments,
                    ],
                ];

                // Some providers require opaque state to be echoed back with the
                // call. Gemini 3 returns `extra_content.google.thought_signature`
                // and rejects the continuation without it. The provider decides
                // whether this endpoint may receive the field (see
                // OpenAiCompatibleProvider::wireMessages()), so a failover to a
                // different vendor never has to understand it.
                if (is_array($call['extra_content'] ?? null) && $call['extra_content'] !== []) {
                    $wireCall['extra_content'] = $call['extra_content'];
                }

                return $wireCall;
            }, $this->toolCalls);
        }

        if ($this->role === self::ROLE_TOOL) {
            $wire['tool_call_id'] = $this->toolCallId;
            // Named so the model can tell which catalogue entry produced this,
            // without the name ever being a place to put an instruction. The
            // call id remains the field providers match on.
            $wire['name'] = $this->toolName;
        }

        return $wire;
    }
}

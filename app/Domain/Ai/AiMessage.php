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
 *   assistant what the model said
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

    private function __construct(
        public string $role,
        public string $content,
        /** Set when this message is the result of a tool call. */
        public ?string $toolName = null,
        /** Set when this message answers a specific call. */
        public ?string $toolCallId = null,
    ) {}

    public static function system(string $content): self
    {
        return new self(self::ROLE_SYSTEM, $content);
    }

    public static function user(string $content): self
    {
        return new self(self::ROLE_USER, $content);
    }

    public static function assistant(string $content, ?string $toolCallId = null): self
    {
        return new self(self::ROLE_ASSISTANT, $content, null, $toolCallId);
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

        if ($this->role === self::ROLE_TOOL) {
            $wire['tool_call_id'] = $this->toolCallId;
            // Named so the model can tell which catalogue entry produced this,
            // without the name ever being a place to put an instruction.
            $wire['name'] = $this->toolName;
        }

        return $wire;
    }
}

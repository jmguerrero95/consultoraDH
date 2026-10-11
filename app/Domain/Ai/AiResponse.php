<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * What a provider answered.
 *
 * Either prose, or one or more calls it wants made. Both together are possible:
 * a model can say what it is about to do and ask for the call in the same turn.
 */
final readonly class AiResponse
{
    /**
     * @param  list<AiToolCall>  $toolCalls
     */
    public function __construct(
        public string $content = '',
        public array $toolCalls = [],
        public ?string $provider = null,
        public ?string $model = null,
        /** The provider's own identifier for this turn, for the audit trail. */
        public ?string $responseId = null,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * A call the model asked for, not yet authorized and not yet run.
 *
 * The name arrives from a model, so it is untrusted until the registry says it is
 * a registered tool. The arguments are likewise untrusted until the tool has
 * validated them.
 */
final readonly class AiToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $providerState  opaque continuation fields the provider
     *                                               requires when the call is replayed (Gemini's
     *                                               `extra_content.google.thought_signature`)
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
        public ?string $id = null,
        public array $providerState = [],
    ) {}
}

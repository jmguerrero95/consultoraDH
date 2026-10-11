<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * One tool as the model is told it exists.
 *
 * The model may only name a tool from a catalogue built here. There is no way to
 * express "run this SQL" or "call this URL" in this type, which is why the
 * absence of those capabilities is structural rather than a matter of the prompt
 * having been written carefully.
 */
final readonly class AiToolDefinition
{
    /**
     * @param  array<string, mixed>  $parameters  JSON Schema object
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->parameters === [] ? $this->defaultSchema() : $this->parameters,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }
}

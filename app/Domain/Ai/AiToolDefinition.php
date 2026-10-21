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
                'parameters' => $this->wireSchema(),
            ],
        ];
    }

    /**
     * The schema, as the providers actually accept it.
     *
     * ## Why this is not just `$this->parameters`
     *
     * Two details of JSON Schema are refused by the OpenAI-compatible
     * providers, and both were found by the LIVE certification against Groq:
     * the whole catalogue was rejected with "invalid JSON schema for tool
     * automation_vocabulary", and no tool worked until it was fixed.
     *
     *  - `properties` is an **object**. PHP's empty array serialises as `[]`,
     *    and `[]` is a list, not an object: the schema has to say `{}`.
     *  - `required` is a list of names, and an **empty** one is malformed. The
     *    key is left out when there is nothing required, which is what the
     *    specification means by "no required properties".
     *
     * The fix lives here, in the one place where a tool becomes a wire
     * payload, so that every tool — the ones written today and the ones
     * written tomorrow — is corrected the same way.
     *
     * @return array<string, mixed>
     */
    private function wireSchema(): array
    {
        $schema = $this->parameters === [] ? $this->defaultSchema() : $this->parameters;

        if (isset($schema['properties']) && is_array($schema['properties']) && $schema['properties'] === []) {
            $schema['properties'] = (object) [];
        }

        if (isset($schema['required']) && is_array($schema['required']) && $schema['required'] === []) {
            unset($schema['required']);
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) []];
    }
}

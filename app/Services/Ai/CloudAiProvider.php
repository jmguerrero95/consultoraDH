<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiMessage;
use App\Domain\Ai\AiResponse;
use App\Domain\Ai\AiToolDefinition;

/**
 * One conversation with a hosted language model.
 *
 * ## The contract, and what is deliberately not in it
 *
 * A provider receives messages and a tool catalogue, and returns a response. It
 * is handed no database handle, no query builder, no filesystem and no shell. The
 * only way a provider can affect the application is by naming a tool from the
 * closed registry it was given, and that call is re-authorized server-side before
 * it does anything.
 *
 * The three value types are small and explicit rather than an array of
 * mixed content, because the boundary matters more than the convenience: a tool
 * result crosses into a model as structured data, and keeping it typed is what
 * stops that from quietly becoming a string concatenated into instructions.
 */
interface CloudAiProvider
{
    /**
     * A tool the model is allowed to call.
     *
     * @param  array<string, mixed>  $parameters  JSON Schema for the arguments
     */
    public function toolDefinition(string $name, string $description, array $parameters = []): AiToolDefinition;

    /**
     * Ask the model to continue the conversation.
     *
     * `$timeoutSeconds` bounds this single call. It is passed by the resolver so
     * that a turn's remaining wall-clock budget — not merely a fixed per-call
     * timeout — decides how long the provider may take, which is what keeps a
     * slow free model from pushing the whole request past the proxy's limit.
     *
     * @param  list<AiMessage>  $messages
     * @param  list<AiToolDefinition>  $tools
     *
     * @throws AiProviderException on any failure, retryable or not
     */
    public function complete(array $messages, array $tools = [], ?int $timeoutSeconds = null, ?string $forceTool = null): AiResponse;
}

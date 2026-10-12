<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Assistant\SuggestionEngine;
use App\Models\AiSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The proactive side of the assistant: the one thing worth saying, if any.
 *
 * ## Three endpoints, deliberately
 *
 *   GET  /ai/suggestion                 the suggestion to show right now, or null
 *   POST /ai/suggestions/{s}/accept     I took it
 *   POST /ai/suggestions/{s}/dismiss    not this one
 *
 * There is no "list". A list is how an assistant becomes a queue, and a queue
 * is the thing this whole feature exists to avoid.
 *
 * ## Silence is a successful answer
 *
 * `GET /ai/suggestion` answers `{"suggestion": null}` on almost every call, and
 * that is not a failure to be worked around in the client. It is the feature
 * working. The status code is 200 for exactly that reason: a caller must not be
 * able to tell "nothing worth saying" from "something went wrong", because the
 * first is the normal case and the second is not.
 *
 * ## Accepting changes nothing
 *
 * `accept` records that a person chose to talk about this. It writes no business
 * data. Anything that changes data goes through the ordinary confirmed-proposal
 * flow inside the conversation it opens.
 */
final class SuggestionController extends Controller
{
    public function __construct(
        private readonly SuggestionEngine $engine,
    ) {}

    /**
     * The suggestion to show right now, or null.
     *
     * `module` and `operation` say where the person is, which is what lets a
     * signal be relevant: an import problem is only worth raising on the import
     * screen, and a collections problem on a receivables one.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['sometimes', 'string', 'max:64'],
            'operation' => ['sometimes', 'string', 'max:64'],
            'conversation_id' => ['sometimes', 'nullable', 'integer', 'exists:ai_conversations,id'],
        ]);

        $suggestion = $this->engine->currentFor(
            $request->user(),
            $validated['module'] ?? 'receivables',
            $validated['operation'] ?? 'client_account',
            $validated['conversation_id'] ?? null,
        );

        return response()->json([
            // `present()` returns null for evidence it does not recognise, and
            // that is deliberate: saying nothing is correct, improvising a
            // sentence from unknown fields is how a suggestion invents itself.
            'suggestion' => $suggestion === null ? null : $this->engine->present($suggestion),
        ]);
    }

    public function accept(Request $request, AiSuggestion $suggestion): JsonResponse
    {
        return $this->resolve($request, $suggestion, accepted: true);
    }

    public function dismiss(Request $request, AiSuggestion $suggestion): JsonResponse
    {
        return $this->resolve($request, $suggestion, accepted: false);
    }

    /**
     * Record the answer to a suggestion.
     *
     * The engine refuses anyone but the suggestion's owner, so someone else's
     * row is answered here with the same 404 as one that does not exist:
     * probing must not confirm that it does.
     */
    private function resolve(Request $request, AiSuggestion $suggestion, bool $accepted): JsonResponse
    {
        try {
            if ($accepted) {
                $this->engine->accept($suggestion, $request->user());
            } else {
                $this->engine->dismiss($suggestion, $request->user());
            }
        } catch (\Throwable $e) {
            if ($e instanceof AiToolRejected) {
                return $this->fail('La sugerencia no está disponible.', 404);
            }

            throw $e;
        }

        return response()->json([
            $accepted ? 'accepted' : 'dismissed' => true,
            'suggestion_id' => $suggestion->id,
        ]);
    }

    /**
     * The same body under both keys, for the reason `AiAssistantController`
     * documents: the shared HTTP client reads `message` when it builds an error.
     */
    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message, 'message' => $message], $status);
    }
}

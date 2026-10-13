<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Assistant\SuggestionEngine;
use App\Models\AiConversation;
use App\Models\AiSuggestion;
use App\Models\Client;
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
    /**
     * Is this a client whose statement could be on screen?
     *
     * Existence only. The permission itself is deliberately *not* decided here:
     * the engine re-reads it on every offer and retires the ones whose author
     * has lost the right, and answering earlier with a bare `null` would strand
     * those rows as available forever, still waiting for somebody who may no
     * longer look at them.
     */
    private function isRealClient(int $clientId): bool
    {
        return Client::query()->whereKey($clientId)->exists();
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['sometimes', 'string', 'max:64'],
            'operation' => ['sometimes', 'string', 'max:64'],
            'conversation_id' => ['sometimes', 'nullable', 'integer'],
            // Which client the statement on screen is about. Optional in the
            // request because the portfolio screen is not about one, and
            // required in effect by the engine for a single client's account.
            'client_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $user = $request->user();

        // A query string is a string. `validated()` returns it verbatim, and the
        // engine's typed parameter is `?int`, so the cast has to happen here -
        // otherwise a perfectly valid request dies with a TypeError and a 500.
        $conversationId = isset($validated['conversation_id']) && $validated['conversation_id'] !== null
            ? (int) $validated['conversation_id']
            : null;

        if ($conversationId !== null) {
            // `exists:ai_conversations,id` proves the conversation is real and
            // nothing about who owns it. An administrator-wide table means any
            // id that exists belongs to *someone*, and accepting theirs would
            // attach this person's suggestion to a conversation they have never
            // seen and cannot read.
            //
            // Scoped by owner rather than validated separately, so the failure
            // mode is the same as for an id that does not exist at all: a 404
            // that reveals nothing about either.
            $owned = AiConversation::query()
                ->whereKey($conversationId)
                ->where('user_id', $user->id)
                ->exists();

            if (! $owned) {
                return $this->fail('La conversación no está disponible.', 404);
            }
        }

        $module = $validated['module'] ?? 'receivables';
        $operation = $validated['operation'] ?? 'client_account';
        $clientId = isset($validated['client_id']) && $validated['client_id'] !== null
            ? (int) $validated['client_id']
            : null;

        // A client's identity has to be checked, not trusted. Without this the
        // endpoint would happily scope its findings to whatever id a caller
        // cared to send.
        //
        // A client that does not exist answers exactly as one with nothing to
        // say: silence. Nothing is revealed about whether the id was ever
        // issued, which is the same refusal the conversation check above makes.
        if ($clientId !== null && ! $this->isRealClient($clientId)) {
            return response()->json(['suggestion' => null]);
        }

        $suggestion = $this->engine->currentFor(
            $user,
            $module,
            $operation,
            $conversationId,
            $clientId,
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

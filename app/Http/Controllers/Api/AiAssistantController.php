<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\AiActionProposal;
use App\Models\AiArtifact;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiConversationManager;
use App\Services\Ai\AiProposalNotConfirmable;
use App\Services\Ai\AiProposalService;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\AiTurnFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * Handles one turn of an AI conversation: question → tool calls → answer/proposal.
 *
 * Read tools execute immediately (registry + authorization check).
 * Write tools produce a persisted proposal; nothing is changed until a person
 * confirms through a separate request.
 *
 * The model never sees business text as instructions; the prompt treats all
 * returned values as data, never as orders.
 */
final class AiAssistantController extends Controller
{
    public function __construct(
        private readonly AiAssistantService $assistant,
        private readonly AiProviderResolver $resolver,
        private readonly AiToolRegistry $registry,
        private readonly AiProposalService $proposalService,
        private readonly AiAvailability $availability,
        private readonly AiConversationManager $conversations,
    ) {}

    /**
     * An error response.
     *
     * The body carries the same text under both `error` and `message`. Every
     * other controller in this application answers with `message`, and that is
     * the key the shared HTTP client reads when it builds an `ApiError`; an
     * answer under `error` alone reached the browser with its detail silently
     * discarded and replaced by a generic status message, which is how a real
     * refusal such as "Esta propuesta ha expirado." became an invisible
     * failure. `error` is kept so anything already reading it keeps working.
     */
    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message, 'message' => $message], $status);
    }

    /**
     * A turn that could not be answered right now, but could later.
     *
     * The important case is the privacy boundary: a turn that discovered a
     * private record and found no provider authorised to receive it must fail
     * *without* the record leaving the server, and the person must be told that
     * plainly rather than shown a 500. `recoverable` is what lets the interface
     * offer a retry instead of treating it as a permanent error.
     */
    private function unavailable(\Throwable $e, ?AiConversation $conversation = null): JsonResponse
    {
        // The provider's own words are never surfaced. The exception message can
        // quote the refusal — which for a quota is often a commercial invitation
        // ("Add credits to unlock…") — so the interface is given the classified,
        // safe sentence instead, and the details stay in the log.
        $message = match (true) {
            $e instanceof AiTurnFailed => 'El asistente no pudo completar la respuesta esta vez. Vuelve a intentarlo en un momento.',
            $e instanceof AiProviderException => $e->publicMessage(),
            default => 'No hay un proveedor de inteligencia artificial disponible en este momento.',
        };

        // The turn just changed what the pool knows about its providers. Drop the
        // cached status so the next read of `/api/ai/status` — which runs no
        // inference — reports the new state instead of a stale "operativo".
        Cache::forget('ai.status');

        return response()->json([
            'error' => $message,
            'message' => $message,
            'recoverable' => true,
            // The failed turn lives in this conversation. Returning its id even
            // on a failure lets the browser adopt it, so an F5 shows the failed
            // question and a retry targets the same turn instead of creating a
            // second conversation with a second row.
            ...($conversation !== null ? ['conversation_id' => $conversation->id] : []),
        ], 503);
    }

    /**
     * Check if user has ai.use permission.
     */
    private function verifyAiUse(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            // No authenticated user; let the route's auth middleware return 401
            return null;
        }
        if (! $user->can('ai.use')) {
            return $this->fail('AI assistant access denied.', 403);
        }

        return null;
    }

    /**
     * List conversations for the current user.
     */
    public function index(Request $request): JsonResponse
    {
        // Most recent activity first. The conversation is touched when a turn
        // succeeds, so a thread continued today rises above one that was merely
        // created earlier and left alone.
        $conversations = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get(['id', 'title', 'created_at', 'updated_at'])
            ->map(fn ($c): array => [
                'id' => $c->id,
                'title' => $c->title,
                'created_at' => $c->created_at?->toDateTimeString(),
                'updated_at' => $c->updated_at?->toDateTimeString(),
            ]);

        return response()->json([
            'conversations' => $conversations,
        ]);
    }

    /**
     * Rename a conversation the caller owns.
     *
     * A title is presentation, never content: the history is untouched, so
     * renaming cannot change what was said or what a proposal will do.
     */
    public function rename(Request $request, AiConversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            return $this->fail('No es tu conversación.', 403);
        }

        $title = (string) $request->input('title', '');

        try {
            $clean = $this->conversations->rename($conversation, $title);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return response()->json([
            'conversation_id' => $conversation->id,
            'title' => $clean,
        ]);
    }

    /**
     * Delete a conversation the caller owns.
     *
     * The files it generated are removed with it; the operational tasks a
     * confirmed proposal may have created are not, because they are business
     * records that do not belong to the conversation.
     */
    public function destroy(Request $request, AiConversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            return $this->fail('No es tu conversación.', 403);
        }

        $this->conversations->delete($conversation);

        return response()->json([
            'deleted' => true,
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * Answer a question, running read tools and proposing writes.
     *
     * POST body: { "conversation_id": 123, "question": "..." }
     * If conversation_id is omitted a new conversation is created.
     */
    /**
     * Open an empty conversation.
     *
     * ## Why this exists
     *
     * `store()` used to be the only way to get a conversation, and it requires a
     * question. That made "attach a file, then ask about it" impossible in the
     * order a person naturally wants to do it: there is no conversation to
     * attach to until something has been asked, and the upload either lands
     * unscoped - where no question in that conversation can reach it - or is
     * refused.
     *
     * Creating it first costs one request and makes the upload belong to a
     * conversation from the start. Nothing is made globally visible by it: the
     * attachment still belongs to this person and this thread.
     */
    public function open(Request $request): JsonResponse
    {
        $deny = $this->verifyAiUse($request);

        if ($deny) {
            return $deny;
        }

        $conversation = AiConversation::query()->create([
            'user_id' => $request->user()->id,
            'title' => $this->conversations->placeholderTitle(),
        ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
            'created_at' => $conversation->created_at?->toDateTimeString(),
        ], 201);
    }

    public function store(Request $request): JsonResponse
    {
        // Check ai.use permission
        $deny = $this->verifyAiUse($request);
        if ($deny) {
            return $deny;
        }

        $data = $request->only(['conversation_id', 'question']);

        if (empty($data['question'])) {
            return $this->fail('Se requiere una pregunta.', 400);
        }

        if (! empty($data['conversation_id'])) {
            $conversation = AiConversation::find($data['conversation_id']);

            if ($conversation === null) {
                return $this->fail('Conversación no encontrada.', 404);
            }

            if ($conversation->user_id !== $request->user()->id) {
                return $this->fail('No es tu conversación.', 403);
            }
        } else {
            $conversation = AiConversation::query()->create([
                'user_id' => $request->user()->id,
                'title' => $this->conversations->placeholderTitle(),
            ]);
        }

        $clientTurnId = $this->clientTurnId($request);

        // §10: a retry of a turn this conversation already completed is replayed,
        // never executed again — the accepted work is not done twice.
        if ($clientTurnId !== null) {
            $replay = $this->assistant->completedTurnResult($conversation, $clientTurnId);

            if ($replay !== null) {
                return response()->json([
                    'conversation_id' => $conversation->id,
                    'title' => $conversation->title,
                    'reply' => $replay['reply'],
                    'provider' => $replay['provider'],
                    'model' => $replay['model'],
                    'proposals' => $replay['proposals'],
                    'tools' => [],
                    'artifacts' => $replay['artifacts'] ?? [],
                    'client_turn_id' => $clientTurnId,
                    'replayed' => true,
                ]);
            }
        }

        try {
            $result = $this->assistant->ask($conversation, $data['question'], $request->user(), $clientTurnId);
        } catch (AiProviderException|AiTurnFailed $e) {
            return $this->unavailable($e, $conversation);
        }

        // The first real question names the thread. A title the person chose is
        // never overwritten, and the derivation costs no provider call.
        $this->conversations->applyDerivedTitle($conversation, $data['question']);
        $conversation->touch();

        return response()->json([
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
            'reply' => $result['reply'],
            'provider' => $result['provider'],
            'model' => $result['model'],
            'proposals' => $result['proposals'],
            'tools' => $result['tools'],
            'artifacts' => $result['artifacts'] ?? [],
            'client_turn_id' => $clientTurnId,
        ]);
    }

    /**
     * Show message history for a conversation.
     */
    public function show(Request $request, AiConversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            return $this->fail('No es tu conversación.', 403);
        }

        $messages = AiMessage::query()
            ->where('ai_conversation_id', $conversation->id)
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'provider', 'model', 'status', 'error', 'client_turn_id', 'created_at'])
            ->map(fn ($m): array => [
                // The id is what an artifact points at, so the interface can put
                // a generated file beside the reply it was generated in.
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'provider' => $m->provider,
                'model' => $m->model,
                // §10 turn state: a failed question survives a reload and can be
                // retried by its `client_turn_id`; a completed one is history.
                'status' => $m->status ?? AiMessage::STATUS_COMPLETED,
                'error' => $m->error,
                'client_turn_id' => $m->client_turn_id,
                'created_at' => $m->created_at?->toDateTimeString(),
            ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
            'messages' => $messages,
            // The files this thread produced. They are part of the conversation,
            // not of a separate panel: after a reload the person sees the PDF
            // they asked for where they asked for it.
            'artifacts' => $this->artifactsFor($conversation),
            // The files the person attached. The A07-R3 widget restored messages
            // and artifacts but not these, so a thread that had been given a
            // spreadsheet came back looking empty. The server is the source of
            // truth, so they are rebuilt from here rather than from memory.
            'attachments' => $this->attachmentsFor($conversation),
            // Proposals (pending write actions) belonging to this conversation,
            // each linked to the message that asked for it so the interface can
            // show them inline. Expired/rejected proposals are excluded.
            'proposals' => $this->proposalsFor($conversation),
        ]);
    }

    /**
     * The pending proposals belonging to a conversation, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function proposalsFor(AiConversation $conversation): array
    {
        return AiActionProposal::query()
            ->where('ai_conversation_id', $conversation->id)
            ->where('user_id', $conversation->user_id)
            ->where('status', AiActionProposal::PENDING)
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->get()
            ->map(fn (AiActionProposal $proposal): array => [
                'id' => $proposal->id,
                'tool' => $proposal->tool,
                'arguments' => $proposal->arguments,
                'expires_at' => $proposal->expires_at?->toDateTimeString(),
                // The message this proposal belongs beside; null for proposals
                // created before this migration.
                'message_id' => $proposal->message_id !== null ? (int) $proposal->message_id : null,
            ])
            ->all();
    }

    /**
     * The attachments belonging to a conversation, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function attachmentsFor(AiConversation $conversation): array
    {
        return AiAttachment::query()
            ->where('ai_conversation_id', $conversation->id)
            ->where('user_id', $conversation->user_id)
            ->orderBy('id')
            ->get()
            ->map(fn (AiAttachment $attachment): array => $attachment->toPublicArray())
            ->all();
    }

    /**
     * The artifacts belonging to a conversation, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function artifactsFor(AiConversation $conversation): array
    {
        return AiArtifact::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $conversation->user_id)
            ->orderBy('id')
            ->get()
            ->map(fn (AiArtifact $artifact): array => [
                'id' => $artifact->id,
                'filename' => $artifact->display_filename,
                'format' => $artifact->format,
                'size_bytes' => (int) $artifact->size_bytes,
                'download_url' => route('api.ai.artifacts.download', ['artifact' => $artifact->id]),
                'expires_at' => $artifact->expires_at?->toDateTimeString(),
                // The reply this file belongs beside; null for one with no reply
                // (a background task, or a turn that produced only a proposal).
                'message_id' => $artifact->message_id !== null ? (int) $artifact->message_id : null,
            ])
            ->all();
    }

    /**
     * Send a new message in an existing conversation.
     */
    public function messages(Request $request, AiConversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            return $this->fail('No es tu conversación.', 403);
        }

        $data = $request->only(['message']);

        if (empty($data['message'])) {
            return $this->fail('Se requiere un mensaje.', 400);
        }

        $clientTurnId = $this->clientTurnId($request);

        // §10: a retry of a turn this conversation already completed is replayed,
        // never executed again.
        if ($clientTurnId !== null) {
            $replay = $this->assistant->completedTurnResult($conversation, $clientTurnId);

            if ($replay !== null) {
                return response()->json([
                    'conversation_id' => $conversation->id,
                    'title' => $conversation->title,
                    'reply' => $replay['reply'],
                    'provider' => $replay['provider'],
                    'model' => $replay['model'],
                    'proposals' => $replay['proposals'],
                    'artifacts' => $replay['artifacts'] ?? [],
                    'client_turn_id' => $clientTurnId,
                    'replayed' => true,
                ]);
            }
        }

        // Service handles all persistence: user message + assistant reply
        try {
            $result = $this->assistant->ask($conversation, $data['message'], $request->user(), $clientTurnId);
        } catch (AiProviderException|AiTurnFailed $e) {
            return $this->unavailable($e, $conversation);
        }

        // A thread opened empty (to attach a file first) takes its title from
        // the first question asked in it.
        $this->conversations->applyDerivedTitle($conversation, $data['message']);
        $conversation->touch();

        return response()->json([
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
            'reply' => $result['reply'],
            'provider' => $result['provider'],
            'model' => $result['model'],
            'proposals' => $result['proposals'],
            'artifacts' => $result['artifacts'] ?? [],
            'client_turn_id' => $clientTurnId,
        ]);
    }

    /**
     * The browser's id for this turn attempt, or null.
     *
     * Conservative on purpose: an id is only accepted if it is a short, opaque
     * token of safe characters. Anything else is treated as "no id", so a
     * malformed or hostile value cannot be used to probe another account's turns
     * (the lookup is scoped by conversation anyway) and never reaches the row.
     */
    private function clientTurnId(Request $request): ?string
    {
        $raw = $request->input('client_turn_id');

        if (! is_string($raw)) {
            return null;
        }

        $clean = trim($raw);

        if ($clean === '' || mb_strlen($clean) > 64) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $clean) === 1 ? $clean : null;
    }

    /**
     * Confirm a write proposal.
     */
    public function confirm(Request $request, $proposalId): JsonResponse
    {
        $proposal = AiActionProposal::findOrFail($proposalId);

        if ($proposal->user_id !== $request->user()->id) {
            return $this->fail('No es tu propuesta.', 403);
        }

        if ($proposal->status !== AiActionProposal::PENDING) {
            return $this->fail('Esa propuesta ya no está disponible.', 400);
        }

        if ($proposal->expires_at->isPast()) {
            return $this->fail('Esta propuesta ha expirado.', 400);
        }

        try {
            $result = $this->proposalService->confirm($proposal, $request->user());

            return response()->json([
                'confirmed' => true,
                'proposal_id' => $proposal->id,
                'tool' => $proposal->tool,
                'result' => $result,
            ]);
        } catch (AiProposalNotConfirmable $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Reject a write proposal.
     */
    public function reject(Request $request, $proposalId): JsonResponse
    {
        $proposal = AiActionProposal::findOrFail($proposalId);

        if ($proposal->user_id !== $request->user()->id) {
            return $this->fail('No es tu propuesta.', 403);
        }

        try {
            $proposal = $this->proposalService->reject($proposal, $request->user());

            return response()->json([
                'rejected' => true,
                'proposal_id' => $proposal->id,
                'tool' => $proposal->tool,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Assistant status: whether a provider is configured and which model it names.
     *
     * No API keys, no credentials, no secrets.
     */
    public function status(): JsonResponse
    {
        // Check ai.use permission
        $deny = $this->verifyAiUse(request());
        if ($deny) {
            return $deny;
        }

        $user = request()->user();
        if (! $user) {
            return $this->fail('AI assistant access denied.', 401);
        }

        // BUG-017. The status used to report only whether each slot was configured —
        // which never changed, so a provider that had been failing for an hour still
        // read as "configured". It now reports which provider is *available right
        // now*: the resolver's own health state decides that, and no provider is
        // contacted to answer, so asking costs nothing. Cached for a few seconds so
        // a polling widget does not re-read it on every tick.
        //
        // BUG-020-R2. The state is now the pool's, not two slots': it says whether
        // a model that recently answered is usable, whether the first choice is
        // down and a backup is carrying the turn, whether nothing has answered
        // recently, or whether a quota wall is the reason. It never says "operativo"
        // merely because a key is configured.
        $seconds = max(1, (int) config('ai.status_cache_seconds', 20));

        $status = Cache::remember('ai.status', $seconds, function (): array {
            $primary = $this->resolver->primary();
            $fallback = $this->resolver->fallback();
            $state = $this->resolver->state();

            // Availability per kind of operation. The single `state` above can be
            // "available" while no provider authorised for private data is ready,
            // so the interface uses this map to say what can actually be done.
            $capabilities = $this->availability->report();

            return [
                'ai_enabled' => config('ai.enabled', false),
                'state' => $state,
                'available' => $this->resolver->anyConfigured()
                    && in_array($state, ['available', 'fallback', 'unchecked'], true),
                // Safe, additive report: per-operation state, counts and model ids.
                // No key, URL, prompt or record.
                'capabilities' => $capabilities,
                'independent_providers' => $this->availability->independentPrivateProviders(),
                // The JSON key stays `active_model`; the method is `activeModel()`.
                // Calling the snake_case name meant `/api/ai/status` threw
                // `Call to undefined method` and answered 500 — so the whole
                // endpoint, and with it the assistant's status, was unreachable.
                'active_model' => $this->resolver->activeModel(),
                // Which model actually answered last, not merely which is first in
                // the configuration. The interface reports the one that worked.
                'last_result' => $this->resolver->lastResult(),
                'cooldowns' => $this->resolver->cooldowns(),
                // The health of every candidate, safe metadata only: model id,
                // endpoint host, state, failure kind, latency. Never a key and
                // never a prompt.
                'models' => $this->resolver->snapshot(),
                // The most recent attempts, so a failure can be explained without
                // reading a log: which model, which task, which HTTP status,
                // which classified error, how long it took, and whether it ended
                // in an answer.
                'diagnostics' => array_slice($this->resolver->diagnostics(), 0, 10),
                'primary' => [
                    'configured' => $primary->isConfigured(),
                    'model' => $primary->model(),
                ],
                'fallback' => [
                    'configured' => $fallback->isConfigured(),
                    'model' => $fallback->model(),
                ],
                'timeout_seconds' => config('ai.timeout_seconds', 30),
                'proposal_ttl_minutes' => config('ai.proposal_ttl_minutes', 15),
            ];
        });

        // The configured base URLs are not published: they are deployment detail, and
        // the interface does not need them to say which model is answering.
        return response()->json($status);
    }
}

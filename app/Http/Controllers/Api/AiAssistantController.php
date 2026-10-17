<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiProposalNotConfirmable;
use App\Services\Ai\AiProposalService;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\AiToolRegistry;
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
        $conversations = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'title', 'created_at'])
            ->map(fn ($c): array => [
                'id' => $c->id,
                'title' => $c->title,
                'created_at' => $c->created_at?->toDateTimeString(),
            ]);

        return response()->json([
            'conversations' => $conversations,
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
            'title' => 'Conversación '.now()->format('Y-m-d H:i'),
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
                'title' => 'Conversación '.now()->format('Y-m-d H:i'),
            ]);
        }

        $result = $this->assistant->ask($conversation, $data['question'], $request->user());

        return response()->json([
            'conversation_id' => $conversation->id,
            'reply' => $result['reply'],
            'provider' => $result['provider'],
            'model' => $result['model'],
            'proposals' => $result['proposals'],
            'tools' => $result['tools'],
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
            ->get(['role', 'content', 'provider', 'model', 'created_at'])
            ->map(fn ($m): array => [
                'role' => $m->role,
                'content' => $m->content,
                'provider' => $m->provider,
                'model' => $m->model,
                'created_at' => $m->created_at?->toDateTimeString(),
            ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $messages,
        ]);
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

        // Service handles all persistence: user message + assistant reply
        $result = $this->assistant->ask($conversation, $data['message'], $request->user());

        return response()->json([
            'conversation_id' => $conversation->id,
            'reply' => $result['reply'],
            'provider' => $result['provider'],
            'model' => $result['model'],
            'proposals' => $result['proposals'],
        ]);
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
        // now*: the resolver's own cooldown state decides that, and no provider is
        // contacted to answer, so asking costs nothing. Cached for a few seconds so
        // a polling widget does not re-read it on every tick.
        $seconds = max(1, (int) config('ai.status_cache_seconds', 20));

        $status = Cache::remember('ai.status', $seconds, function (): array {
            $primary = $this->resolver->primary();
            $fallback = $this->resolver->fallback();

            return [
                'ai_enabled' => config('ai.enabled', false),
                'state' => $this->resolver->state(),
                'available' => $this->resolver->anyConfigured()
                    && $this->resolver->state() !== 'unavailable',
                'active_model' => $this->resolver->active_model(),
                'cooldowns' => $this->resolver->cooldowns(),
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

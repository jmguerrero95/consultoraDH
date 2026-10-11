<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\AiConversation;
use App\Models\AiActionProposal;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\AiProposalService;
use App\Domain\Ai\AiMessage as AiMessageModel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;

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
            return response()->json(['error' => 'AI assistant access denied.'], 403);
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
    public function store(Request $request): JsonResponse
    {
        // Check ai.use permission
        $deny = $this->verifyAiUse($request);
        if ($deny) {
            return $deny;
        }

        $data = $request->only(['conversation_id', 'question']);

        if (empty($data['question'])) {
            return response()->json(['error' => 'Se requiere una pregunta.'], 400);
        }

        if (! empty($data['conversation_id'])) {
            $conversation = AiConversation::find($data['conversation_id']);

            if ($conversation === null) {
                return response()->json(['error' => 'Conversación no encontrada.'], 404);
            }

            if ($conversation->user_id !== $request->user()->id) {
                return response()->json(['error' => 'No es tu conversación.'], 403);
            }
        } else {
            $conversation = AiConversation::query()->create([
                'user_id' => $request->user()->id,
                'title' => 'Conversación ' . now()->format('Y-m-d H:i'),
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
            return response()->json(['error' => 'No es tu conversación.'], 403);
        }

        $messages = AiMessageModel::query()
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
            return response()->json(['error' => 'No es tu conversación.'], 403);
        }

        $data = $request->only(['message']);

        if (empty($data['message'])) {
            return response()->json(['error' => 'Se requiere un mensaje.'], 400);
        }

        // Store user message - this is the ONE persistence point
        AiMessageModel::query()->create([
            'ai_conversation_id' => $conversation->id,
            'role' => AiMessageModel::ROLE_USER,
            'content' => $data['message'],
        ]);

        // Immediately try to answer (same flow as store, but scoped to convo)
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
            return response()->json(['error' => 'No es tu propuesta.'], 403);
        }

        if ($proposal->status !== \App\Models\AiActionProposal::PENDING) {
            return response()->json(['error' => 'Esa propuesta ya no está disponible.'], 400);
        }

        if ($proposal->expires_at->isPast()) {
            return response()->json(['error' => 'Esta propuesta ha expirado.'], 400);
        }

        try {
            $result = $this->proposalService->confirm($proposal, $request->user());

            return response()->json([
                'confirmed' => true,
                'proposal_id' => $proposal->id,
                'tool' => $proposal->tool,
                'result' => $result,
            ]);
        } catch (\App\Services\Ai\AiProposalNotConfirmable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reject a write proposal.
     */
    public function reject(Request $request, $proposalId): JsonResponse
    {
        $proposal = AiActionProposal::findOrFail($proposalId);

        if ($proposal->user_id !== $request->user()->id) {
            return response()->json(['error' => 'No es tu propuesta.'], 403);
        }

        try {
            $proposal = $this->proposalService->reject($proposal, $request->user());

            return response()->json([
                'rejected' => true,
                'proposal_id' => $proposal->id,
                'tool' => $proposal->tool,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
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
            return response()->json(['error' => 'AI assistant access denied.'], 401);
        }

        $primaryConfigured = ! empty(config('ai.primary.base_url'))
            && ! empty(config('ai.primary.model'));

        $fallbackConfigured = ! empty(config('ai.fallback.base_url'))
            && ! empty(config('ai.fallback.model'));

        return response()->json([
            'ai_enabled' => config('ai.enabled', false),
            'primary' => [
                'configured' => $primaryConfigured,
                'model' => config('ai.primary.model'),
                'base_url' => config('ai.primary.base_url'),
            ],
            'fallback' => [
                'configured' => $fallbackConfigured,
                'model' => config('ai.fallback.model'),
                'base_url' => config('ai.fallback.base_url'),
            ],
            'timeout_seconds' => config('ai.timeout_seconds', 30),
            'proposal_ttl_minutes' => config('ai.proposal_ttl_minutes', 15),
        ]);
    }
}

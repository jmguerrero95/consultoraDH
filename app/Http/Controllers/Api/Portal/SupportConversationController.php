<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Domain\Support\Actions\AppendClientMessage;
use App\Domain\Support\Actions\CreateSupportConversation;
use App\Domain\Support\Actions\MarkConversationRead;
use App\Domain\Support\Actions\StoreSupportAttachment;
use App\Domain\Support\SupportConversationPriority;
use App\Domain\Support\SupportMessageChannel;
use App\Domain\Support\SupportMessageSenderKind;
use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'client' || ! $user->client_id) {
            return response()->json(['message' => 'Acceso denegado'], 403);
        }

        $query = SupportConversation::query()
            ->where('client_id', $user->client_id)
            ->where('status', '!=', 'closed')
            ->with(['queue', 'lastMessage', 'readStates' => function ($q) use ($user) {
                $q->where('user_id', $user->id);
            }])
            ->orderByDesc('last_message_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $conversations = $query->paginate($request->integer('per_page', 25));

        return response()->json($conversations);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'client' || ! $user->client_id) {
            return response()->json(['message' => 'Acceso denegado'], 403);
        }

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body_text' => 'required|string|max:65535',
            'queue_id' => 'nullable|integer|exists:support_queues,id',
            'attachment_ids' => 'sometimes|array',
            'attachment_ids.*' => 'integer|exists:support_message_attachments,id',
        ]);

        $conversation = app(CreateSupportConversation::class)->execute([
            'client_id' => $user->client_id,
            // Read with a default, not by key. The rule is `nullable`, so a
            // request that simply does not choose a queue leaves the key out of
            // the validated array entirely, and reading it directly raised
            // "Undefined array key" — a 500 on every conversation opened from
            // the portal without naming a queue, which is what the portal's own
            // form does. `CreateSupportConversation` resolves the default queue
            // when this is null.
            'queue_id' => $validated['queue_id'] ?? null,
            'subject' => $validated['subject'],
            'priority' => SupportConversationPriority::Normal,
            'origin_channel' => SupportMessageChannel::Portal,
            'first_message' => $validated['body_text'],
            'sender_kind' => SupportMessageSenderKind::Client,
        ], $user);

        if (! empty($validated['attachment_ids'])) {
            SupportMessageAttachment::whereIn('id', $validated['attachment_ids'])
                ->whereNull('support_message_id')
                ->update(['support_message_id' => $conversation->last_message_id]);
        }

        return response()->json($conversation->load('queue', 'messages.attachments'), 201);
    }

    public function show(Request $request, SupportConversation $conversation): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'client' || ! $user->client_id || $conversation->client_id !== $user->client_id) {
            return response()->json(['message' => 'Conversación no encontrada'], 404);
        }

        $perPage = $request->integer('per_page', 50);
        $beforeId = $request->integer('before_id');

        $messagesQuery = SupportMessage::where('conversation_id', $conversation->id)
            ->where('client_visible', true)
            ->with(['author', 'attachments'])
            ->orderByDesc('created_at');

        if ($beforeId) {
            $messagesQuery->where('id', '<', $beforeId);
        }

        $messages = $messagesQuery->limit($perPage + 1)->get();

        $hasMore = $messages->count() > $perPage;
        if ($hasMore) {
            $messages = $messages->take($perPage);
        }

        $conversation->load(['queue', 'readStates' => function ($q) use ($user) {
            $q->where('user_id', $user->id);
        }]);
        $conversation->setRelation('messages', $messages->reverse()->values());

        $response = response()->json($conversation);

        if ($hasMore) {
            $response->header('X-Has-More', 'true');
            $lastMessage = $messages->last();
            if ($lastMessage) {
                $response->header('X-Next-Cursor', (string) $lastMessage->id);
            }
        }

        return $response;
    }

    public function sendMessage(Request $request, SupportConversation $conversation): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'client' || ! $user->client_id || $conversation->client_id !== $user->client_id) {
            return response()->json(['message' => 'Conversación no encontrada'], 404);
        }

        if (! $conversation->canReceiveClientMessage()) {
            return response()->json(['message' => 'Esta conversación está cerrada'], 409);
        }

        $validated = $request->validate([
            'body_text' => 'required|string|max:65535',
            'attachment_ids' => 'sometimes|array',
            'attachment_ids.*' => 'integer|exists:support_message_attachments,id',
        ]);

        $message = app(AppendClientMessage::class)->execute($conversation, $validated['body_text'], $user, $validated['attachment_ids'] ?? []);

        return response()->json($message->load('attachments'), 201);
    }

    public function uploadAttachment(Request $request, SupportConversation $conversation, StoreSupportAttachment $store): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'client' || ! $user->client_id || $conversation->client_id !== $user->client_id) {
            return response()->json(['message' => 'Conversación no encontrada'], 404);
        }

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.StoreSupportAttachment::MAX_KILOBYTES,
                'mimetypes:'.StoreSupportAttachment::MIMES,
            ],
        ], [], ['file' => 'archivo']);

        /*
         * Validation, the extension rule, storage and the row are {@see StoreSupportAttachment}'s,
         * shared with the staff upload. It used to be inline here, and the staff endpoint was
         * then written separately — which is how the two could disagree about the extension a
         * file is stored under.
         */
        return response()->json(
            $store->store($validated['file'], $conversation, $user, 'portal'),
            201,
        );
    }

    /**
     * Serve an attachment as a download.
     *
     * Two outcomes, and the signature says so.
     *
     * A refusal is a `JsonResponse` — 403 or 404 with a message the interface
     * can show. A success is a `StreamedResponse`, because `Storage::download()`
     * streams rather than buffering the file into memory.
     *
     * Declaring only `JsonResponse` meant every successful download raised a
     * `TypeError`: the endpoint could refuse correctly but could never actually
     * serve a file. Declaring only `StreamedResponse` simply moved the same
     * error onto every refusal.
     */
    public function downloadAttachment(Request $request, SupportConversation $conversation, SupportMessageAttachment $attachment): JsonResponse|StreamedResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'client' || ! $user->client_id || $conversation->client_id !== $user->client_id) {
            return response()->json(['message' => 'Conversación no encontrada'], 404);
        }

        // Ownership from the attachment's own NOT NULL column, so there is no
        // join to miss and nothing to dereference if a message is not attached
        // yet. See the staff endpoint for the same reasoning at more length.
        if ($attachment->conversation_id !== $conversation->id) {
            return response()->json(['message' => 'Adjunto no encontrado'], 404);
        }

        // A client may only download what is visible to a client. An attachment
        // whose message is not attached yet is treated as not visible: it has
        // been uploaded but not sent, so there is nothing to disclose it under.
        if ($attachment->message === null || ! $attachment->message->client_visible) {
            return response()->json(['message' => 'No puede descargar adjuntos de notas internas'], 403);
        }

        if (! Storage::disk('local')->exists($attachment->stored_path)) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_name);
    }

    public function markRead(Request $request, SupportConversation $conversation): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'client' || ! $user->client_id || $conversation->client_id !== $user->client_id) {
            return response()->json(['message' => 'Conversación no encontrada'], 404);
        }

        $validated = $request->validate([
            'up_to_message_id' => 'required|integer|exists:support_messages,id',
        ]);

        $message = SupportMessage::findOrFail($validated['up_to_message_id']);

        if ($message->conversation_id !== $conversation->id) {
            return response()->json(['message' => 'El mensaje no pertenece a esta conversación'], 422);
        }

        if (! $message->client_visible) {
            return response()->json(['message' => 'No puede marcar como leído un mensaje interno'], 422);
        }

        $result = app(MarkConversationRead::class)->execute($conversation, $user, $message);

        return response()->json($result);
    }
}

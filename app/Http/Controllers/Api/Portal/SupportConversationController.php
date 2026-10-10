<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Domain\Support\Actions\AppendClientMessage;
use App\Domain\Support\Actions\CreateSupportConversation;
use App\Domain\Support\Actions\MarkConversationRead;
use App\Domain\Support\SupportConversationPriority;
use App\Domain\Support\SupportMessageChannel;
use App\Domain\Support\SupportMessageSenderKind;
use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            'queue_id' => $validated['queue_id'],
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

        if (!$conversation->canReceiveClientMessage()) {
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

    public function uploadAttachment(Request $request, SupportConversation $conversation): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'client' || ! $user->client_id || $conversation->client_id !== $user->client_id) {
            return response()->json(['message' => 'Conversación no encontrada'], 404);
        }

        $validated = $request->validate([
            'file' => 'required|file|max:10240|mimetypes:application/pdf,image/jpeg,image/png,text/csv,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,audio/webm,audio/ogg,audio/mpeg,audio/mp4',
        ]);

        $file = $validated['file'];
        $uuid = Str::uuid();
        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $storedPath = "support-attachments/{$uuid}.{$extension}";

        // Store file with atomic cleanup on failure
        try {
            Storage::disk('local')->put($storedPath, $file->get());

            // Calculate SHA-256
            $sha256 = hash_file('sha256', $file->getRealPath());

            $attachment = SupportMessageAttachment::create([
                'support_message_id' => null, // Will be linked when message is sent
                'kind' => str_starts_with($file->getMimeType(), 'audio/') ? 'audio' : 'file',
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'sha256' => $sha256,
                'uploaded_by_user_id' => $user->id,
                'source_channel' => 'portal',
            ]);
        } catch (\Throwable $e) {
            // Cleanup on failure
            if (Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $e;
        }

        return response()->json($attachment, 201);
    }

    public function downloadAttachment(Request $request, SupportConversation $conversation, SupportMessageAttachment $attachment): JsonResponse
    {
        $user = $request->user();

        if ($user->account_type !== 'client' || ! $user->client_id || $conversation->client_id !== $user->client_id) {
            return response()->json(['message' => 'Conversación no encontrada'], 404);
        }

        // Verify attachment belongs to this conversation
        $message = $attachment->message;
        if (!$message || $message->conversation_id !== $conversation->id) {
            return response()->json(['message' => 'Adjunto no encontrado'], 404);
        }

        // Only allow download of client-visible attachments
        if (!$attachment->message->client_visible) {
            return response()->json(['message' => 'No puede descargar adjuntos de notas internas'], 403);
        }

        if (!Storage::disk('local')->exists($attachment->stored_path)) {
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

        if (!$message->client_visible) {
            return response()->json(['message' => 'No puede marcar como leído un mensaje interno'], 422);
        }

        $result = app(MarkConversationRead::class)->execute($conversation, $user, $message);

        return response()->json($result);
    }
}
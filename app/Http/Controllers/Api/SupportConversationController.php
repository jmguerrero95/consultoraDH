<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Support\Actions\AddInternalNote;
use App\Domain\Support\Actions\AppendStaffReply;
use App\Domain\Support\Actions\AssignConversation;
use App\Domain\Support\Actions\CloseConversation;
use App\Domain\Support\Actions\ChangeConversationPriority;
use App\Domain\Support\Actions\ChangeConversationQueue;
use App\Domain\Support\Actions\MarkConversationRead;
use App\Domain\Support\Actions\ReopenConversation;
use App\Domain\Support\Actions\ResolveConversation;
use App\Domain\Support\SupportConversationPriority;
use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportQueue;
use App\Models\SupportQueueMember;
use App\Models\SupportMessageAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;

class SupportConversationController extends Controller
{
    public function show(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request->user(), $conversation);

        $perPage = $request->integer('per_page', 50);
        $beforeId = $request->integer('before_id');

        $messagesQuery = SupportMessage::where('conversation_id', $conversation->id)
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

        $conversation->load(['client', 'queue', 'assignee']);
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
        $this->authorizeConversationAccess($request->user(), $conversation);

        $validated = $request->validate([
            'body_text' => 'required|string|max:65535',
            'attachment_ids' => 'sometimes|array',
            'attachment_ids.*' => 'integer|exists:support_message_attachments,id',
        ]);

        $message = app(AppendStaffReply::class)->execute(
            $conversation,
            $validated['body_text'],
            $request->user(),
            $validated['attachment_ids'] ?? []
        );

        return response()->json($message->load('attachments'), 201);
    }

    public function addNote(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request->user(), $conversation);

        $validated = $request->validate([
            'body_text' => 'required|string|max:65535',
            'attachment_ids' => 'sometimes|array',
            'attachment_ids.*' => 'integer|exists:support_message_attachments,id',
        ]);

        $message = app(AddInternalNote::class)->execute(
            $conversation,
            $validated['body_text'],
            $request->user(),
            $validated['attachment_ids'] ?? []
        );

        return response()->json($message->load('attachments'), 201);
    }

    public function markRead(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request->user(), $conversation);

        $validated = $request->validate([
            'up_to_message_id' => 'required|integer|exists:support_messages,id',
        ]);

        $message = SupportMessage::findOrFail($validated['up_to_message_id']);

        if ($message->conversation_id !== $conversation->id) {
            return response()->json(['message' => 'El mensaje no pertenece a esta conversación'], 422);
        }

        if (!$message->isClientVisible() && $request->user()->account_type === 'client') {
            return response()->json(['message' => 'No puede marcar como leído un mensaje interno'], 422);
        }

        $result = app(MarkConversationRead::class)->execute($conversation, $request->user(), $message);

        return response()->json($result);
    }

    public function assign(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request->user(), $conversation);

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $assignee = User::findOrFail($validated['user_id']);

        $conversation = app(AssignConversation::class)->execute($conversation, $assignee, $request->user());

        return response()->json($conversation->load('assignee'));
    }

    public function changeQueue(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request->user(), $conversation);

        $validated = $request->validate([
            'queue_id' => 'required|integer|exists:support_queues,id',
            'assignee_user_id' => 'sometimes|nullable|integer|exists:users,id',
        ]);

        $newQueue = SupportQueue::findOrFail($validated['queue_id']);
        $newAssignee = $validated['assignee_user_id'] ? User::find($validated['assignee_user_id']) : null;

        $conversation = app(ChangeConversationQueue::class)->execute($conversation, $newQueue, $request->user(), $newAssignee);

        return response()->json($conversation->load('queue', 'assignee'));
    }

    public function changePriority(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request->user(), $conversation);

        $validated = $request->validate([
            'priority' => ['required', Rule::in(['normal', 'high', 'urgent'])],
        ]);

        $conversation = app(ChangeConversationPriority::class)->execute($conversation, SupportConversationPriority::from($validated['priority']));

        return response()->json($conversation);
    }

    public function resolve(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request->user(), $conversation);

        $conversation = app(ResolveConversation::class)->execute($conversation, $request->user());

        return response()->json($conversation);
    }

    public function close(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request->user(), $conversation);

        try {
            $conversation = app(CloseConversation::class)->execute($conversation, $request->user());

            return response()->json($conversation);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function reopen(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request->user(), $conversation);

        $conversation = app(ReopenConversation::class)->execute($conversation, $request->user());

        return response()->json($conversation);
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
        $this->authorizeConversationAccess($request->user(), $conversation);

        /*
         * Ownership is read from the attachment's own `conversation_id`.
         *
         * It previously went through `$attachment->message`, which has two
         * problems. An attachment may exist with no message yet — it is uploaded
         * before the message that carries it is sent — so the join could be null;
         * and the follow-up `!$attachment->message->client_visible` then
         * dereferenced that null unconditionally, turning a denied download into
         * a 500. Reading the NOT NULL ownership column makes both impossible:
         * there is no null to reach through, and no question about what a
         * missing message should mean.
         */
        if ($attachment->conversation_id !== $conversation->id) {
            return response()->json(['message' => 'Adjunto no encontrado'], 404);
        }

        /*
         * An attachment on an internal note is staff-only.
         *
         * Client visibility is a property of the message the attachment hangs
         * off, so that is where it is read — but only once it is known to be
         * there.
         */
        if ($attachment->message === null) {
            return response()->json(['message' => 'Adjunto no encontrado'], 404);
        }

        if (! $attachment->message->client_visible && $request->user()->account_type === 'client') {
            return response()->json(['message' => 'No puede descargar adjuntos de notas internas'], 403);
        }

        if (!Storage::disk('local')->exists($attachment->stored_path)) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_name);
    }

    private function authorizeConversationAccess(User $user, SupportConversation $conversation): void
    {
        if ($user->hasPermissionTo('support.view_all')) {
            return;
        }

        $isMember = SupportQueueMember::where('queue_id', $conversation->queue_id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isMember && $conversation->assigned_to_user_id !== $user->id) {
            abort(403, 'No tiene acceso a esta conversación');
        }
    }
}
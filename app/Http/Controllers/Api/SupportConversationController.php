<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Support\Actions\AddInternalNote;
use App\Domain\Support\Actions\AppendStaffReply;
use App\Domain\Support\Actions\AssignConversation;
use App\Domain\Support\Actions\ChangeConversationPriority;
use App\Domain\Support\Actions\ChangeConversationQueue;
use App\Domain\Support\Actions\CloseConversation;
use App\Domain\Support\Actions\CreateSupportConversation;
use App\Domain\Support\Actions\LinkSupportAttachments;
use App\Domain\Support\Actions\MarkConversationRead;
use App\Domain\Support\Actions\ReopenConversation;
use App\Domain\Support\Actions\ResolveConversation;
use App\Domain\Support\Actions\StoreSupportAttachment;
use App\Domain\Support\SupportAttachmentRejected;
use App\Domain\Support\SupportConversationPriority;
use App\Domain\Support\SupportMessageChannel;
use App\Domain\Support\SupportMessageSenderKind;
use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportQueue;
use App\Models\SupportQueueMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportConversationController extends Controller
{
    /**
     * A conversation opened by staff, on behalf of a client.
     *
     * ## Why this did not exist
     *
     * The portal had `store`; the staff screen did not. `api.support.createConversation()`
     * had always posted to `/api/support/conversations`, but no route answered it — the SPA
     * catch-all returned `index.html`, the client failed parsing it as JSON, and "Nueva
     * conversación" opened the conversation screen with no id, which then asked the server
     * for conversation `NaN`. There is now an endpoint, and it is the one the client already
     * called.
     *
     * ## Why the client is required here and optional in the portal
     *
     * A staff member opening a case is doing so for a named person — that is who the thread
     * is about, and `client_id` is what every later screen and permission check reads. The
     * portal can infer it from the session; here it has to be stated.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'subject' => 'required|string|max:255',
            'body_text' => 'required|string|max:65535',
            'queue_id' => 'nullable|integer|exists:support_queues,id',
            'priority' => 'nullable|string|in:normal,high,urgent',
            'attachment_ids' => 'sometimes|array',
            'attachment_ids.*' => 'integer|exists:support_message_attachments,id',
        ]);

        try {
            $conversation = DB::transaction(function () use ($validated, $request) {
                $conversation = app(CreateSupportConversation::class)->execute([
                    'client_id' => $validated['client_id'],
                    'queue_id' => $validated['queue_id'] ?? null,
                    'subject' => $validated['subject'],
                    'priority' => isset($validated['priority'])
                        ? SupportConversationPriority::from($validated['priority'])
                        : SupportConversationPriority::Normal,
                    'origin_channel' => SupportMessageChannel::Staff,
                    'first_message' => $validated['body_text'],
                    'sender_kind' => SupportMessageSenderKind::Staff,
                ], $request->user());

                if (! empty($validated['attachment_ids'])) {
                    app(LinkSupportAttachments::class)->execute(
                        $conversation,
                        (int) $conversation->last_message_id,
                        $validated['attachment_ids'],
                        $request->user(),
                    );
                }

                return $conversation;
            });
        } catch (\InvalidArgumentException $e) {
            // No default queue configured: a configuration state, not a bug, and the
            // operator is told which one is missing rather than shown a 500.
            return response()->json([
                'message' => 'No hay una cola por defecto configurada. Elija una cola.',
                'code' => 'no_default_queue',
            ], 409);
        } catch (SupportAttachmentRejected $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
            ], 422);
        }

        return response()->json($conversation->load('client', 'queue', 'messages.attachments'), 201);
    }

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

        try {
            $message = app(AppendStaffReply::class)->execute(
                $conversation,
                $validated['body_text'],
                $request->user(),
                $validated['attachment_ids'] ?? []
            );
        } catch (SupportAttachmentRejected $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
            ], 422);
        }

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

        try {
            $message = app(AddInternalNote::class)->execute(
                $conversation,
                $validated['body_text'],
                $request->user(),
                $validated['attachment_ids'] ?? []
            );
        } catch (SupportAttachmentRejected $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason,
            ], 422);
        }

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

        if (! $message->isClientVisible() && $request->user()->account_type === 'client') {
            return response()->json(['message' => 'No puede marcar como leído un mensaje interno'], 422);
        }

        $result = app(MarkConversationRead::class)->execute($conversation, $request->user(), $message);

        return response()->json($result);
    }

    public function assign(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request->user(), $conversation);

        // `present|nullable` so the "Sin asignar" option works. The rule used to be
        // `required|integer`, so choosing it sent `user_id: null` and the server
        // answered 422: the one control that removes an assignee could never succeed.
        $validated = $request->validate([
            'user_id' => 'present|nullable|integer|exists:users,id',
        ]);

        $assignee = $validated['user_id'] === null ? null : User::findOrFail($validated['user_id']);

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
        // Read with a default for the same reason as the queue: the rule is
        // `sometimes|nullable`, so a request that only names a queue leaves the
        // key out and reading it directly raised "Undefined array key".
        $newAssignee = isset($validated['assignee_user_id']) && $validated['assignee_user_id'] !== null
            ? User::find($validated['assignee_user_id'])
            : null;

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
    /**
     * POST /api/support/conversations/{conversation}/attachments
     *
     * Upload a file or a recorded voice note to a conversation.
     *
     * ## Why this existed only on the portal side
     *
     * The conversation screen posts here — for its file picker and for the audio recorder — and
     * the route was never declared on this side, so both returned **404**. The client's portal
     * upload worked because it posted to `/api/portal/support/conversations/{id}/attachments`.
     * The frontend call was not wrong; the staff route was missing.
     *
     * Authority is `support.reply`, which is the same permission that lets somebody post into
     * this conversation: uploading an attachment is part of replying. The per-conversation check
     * is the same one every other method here uses — queue membership or assignment — so a
     * supervisor with `support.reply` who is not on the queue is still refused.
     *
     * Storage, validation and the extension rule are **not** repeated here: they live in
     * {@see StoreSupportAttachment}, which the portal controller calls too, so the two sides
     * cannot drift on the parts that matter.
     */
    public function uploadAttachment(Request $request, SupportConversation $conversation, StoreSupportAttachment $store): JsonResponse
    {
        $user = $request->user();

        $this->authorizeConversationAccess($user, $conversation);

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.StoreSupportAttachment::MAX_KILOBYTES,
                'mimetypes:'.StoreSupportAttachment::MIMES,
            ],
        ], [], ['file' => 'archivo']);

        return response()->json(
            $store->store($validated['file'], $conversation, $user, 'staff'),
            201,
        );
    }

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

        if (! Storage::disk('local')->exists($attachment->stored_path)) {
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

        if (! $isMember && $conversation->assigned_to_user_id !== $user->id) {
            abort(403, 'No tiene acceso a esta conversación');
        }
    }
}

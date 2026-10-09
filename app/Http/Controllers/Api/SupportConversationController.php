<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Support\Actions\AddInternalNote;
use App\Domain\Support\Actions\AppendStaffReply;
use App\Domain\Support\Actions\AssignConversation;
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
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportConversationController extends Controller
{
    public function show(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request->user(), $conversation);

        $conversation->load([
            'client',
            'queue',
            'assignee',
            'messages.author',
            'messages.attachments',
        ]);

        return response()->json($conversation);
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

        if (! $message->isClientVisible() && $request->user()->account_type === 'client') {
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

        return DB::transaction(function () use ($conversation, $request) {
            $conversation->lockForUpdate();
            $conversation = $conversation->fresh();

            if ($conversation->isTerminal()) {
                return response()->json(['message' => 'La conversación ya está cerrada'], 409);
            }

            $conversation->status = $conversation->status->onClose();
            $conversation->closed_at = now();
            $conversation->closed_by = $request->user()->id;
            $conversation->save();

            return response()->json($conversation);
        });
    }

    public function reopen(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request->user(), $conversation);

        $conversation = app(ReopenConversation::class)->execute($conversation, $request->user());

        return response()->json($conversation);
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

<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\SupportConversation;
use App\Models\SupportQueueMember;
use App\Models\User;

/**
 * A support conversation, read under A06's own authorization.
 *
 * ## The same rule the support screen applies
 *
 * A06 decides access to a conversation in one place: `support.view_all` sees
 * every conversation, and anyone else must be a member of its queue or its
 * assignee. That rule is reproduced here rather than approximated, because a
 * second and looser version of "may I read this conversation" is how an assistant
 * becomes a way around the inbox.
 *
 * Internal notes are never returned. A note is staff-only by definition, and the
 * portal does not show it; an assistant that summarized one would disclose it
 * through a channel the product deliberately closed.
 */
final class SupportSummaryTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'support_summary';
    }

    public function description(): string
    {
        return 'Resumen de una conversacion de soporte: estado, prioridad, cola y los mensajes visibles para quien consulta. No incluye notas internas.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'conversation_id' => ['type' => 'integer', 'description' => 'Identificador de la conversacion.'],
                'limit' => ['type' => 'integer', 'description' => 'Mensajes a devolver (1 a 50).'],
            ],
            'required' => ['conversation_id'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        $limit = $arguments['limit'] ?? 20;

        if (! is_int($limit) && ! (is_string($limit) && ctype_digit($limit))) {
            throw new AiToolRejected("El argumento 'limit' debe ser un numero.");
        }

        return [
            'conversation_id' => $this->requireId($arguments, 'conversation_id'),
            'limit' => max(1, min(50, (int) $limit)),
        ];
    }

    public function describe(array $arguments): string
    {
        return 'Consultar la conversacion de soporte #'.$arguments['conversation_id'].'.';
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'support.view');

        $conversation = SupportConversation::with(['queue', 'assignee', 'client'])
            ->find($arguments['conversation_id']);

        if ($conversation === null) {
            return ['found' => false, 'message' => 'No existe esa conversacion.'];
        }

        if (! $this->mayRead($user, $conversation)) {
            throw new AiToolRejected('No tiene acceso a esta conversacion.');
        }

        // `client_visible` only: an internal note never crosses this boundary.
        $messages = $conversation->messages()
            ->where('client_visible', true)
            ->orderBy('id')
            ->limit($arguments['limit'])
            ->get(['id', 'sender_kind', 'body_text', 'created_at']);

        return [
            'found' => true,
            'conversation_id' => $conversation->id,
            'subject' => $conversation->subject,
            'status' => $conversation->status->value,
            'priority' => $conversation->priority->value,
            'queue' => $conversation->queue?->name,
            'client_id' => $conversation->client_id,
            'messages' => $messages->map(fn ($message): array => [
                'sender_kind' => $message->sender_kind,
                'body_text' => $message->body_text,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * A06's rule, unchanged: `support.view_all`, or queue membership, or being
     * the assignee.
     */
    private function mayRead(User $user, SupportConversation $conversation): bool
    {
        if ($user->can('support.view_all')) {
            return true;
        }

        if ($conversation->assigned_to_user_id === $user->id) {
            return true;
        }

        return $conversation->queue_id !== null && SupportQueueMember::query()
            ->where('queue_id', $conversation->queue_id)
            ->where('user_id', $user->id)
            ->exists();
    }
}

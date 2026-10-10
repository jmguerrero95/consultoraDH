<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Events\SupportConversationClosed;
use App\Domain\Support\SupportConversationStatus;
use App\Models\SupportConversation;
use App\Models\SupportReadState;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Close a conversation that will not be resolved.
 *
 * Mirrors ResolveConversation so that closing stops the SLA clocks and
 * clears the closer's unread state. Leaving that to the controller meant a
 * closed conversation kept a resolution deadline that nothing would ever
 * clear, and the scanner went on measuring it.
 */
class CloseConversation
{
    public function execute(SupportConversation $conversation, User $closer): SupportConversation
    {
        return DB::transaction(function () use ($conversation, $closer) {
            // Re-read conversation under row lock to ensure authoritative state
            $conversation = SupportConversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($conversation->isTerminal()) {
                throw new \DomainException('Cannot close a terminal conversation');
            }

            $conversation->status = SupportConversationStatus::Closed;
            $conversation->closed_at = now();
            $conversation->closed_by = $closer->id;
            $conversation->resolution_due_at = null;
            $conversation->next_staff_response_due_at = null;
            $conversation->save();

            $readState = SupportReadState::firstOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => $closer->id],
                ['last_read_message_id' => null, 'read_at' => null]
            );
            $lastMessage = $conversation->lastMessage;
            if ($lastMessage && ($readState->last_read_message_id === null || $readState->last_read_message_id < $lastMessage->id)) {
                $readState->update(['last_read_message_id' => $lastMessage->id, 'read_at' => now()]);
            }

            DB::afterCommit(function () use ($conversation, $closer) {
                event(new SupportConversationClosed($conversation->fresh(['queue', 'closer']), $closer));
            });

            return $conversation->fresh(['queue', 'closer']);
        });
    }
}
<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Events\SupportConversationResolved;
use App\Domain\Support\Events\SupportReadStateUpdated;
use App\Domain\Support\SupportConversationStatus;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportReadState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Dispatch;

class ResolveConversation
{
    public function execute(SupportConversation $conversation, User $resolver): SupportConversation
    {
        return DB::transaction(function () use ($conversation, $resolver) {
            // Re-read conversation under row lock to ensure authoritative state
            $conversation = SupportConversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($conversation->isTerminal()) {
                throw new \DomainException('Cannot resolve a terminal conversation');
            }

            $conversation->status = SupportConversationStatus::Resolved;
            $conversation->resolved_at = now();
            $conversation->resolved_by = $resolver->id;
            $conversation->resolution_due_at = null;
            $conversation->next_staff_response_due_at = null;
            $conversation->save();

            // Clear unread for resolver
            $readState = SupportReadState::firstOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => $resolver->id],
                ['last_read_message_id' => null, 'read_at' => null]
            );
            $lastMessage = $conversation->lastMessage;
            if ($lastMessage && ($readState->last_read_message_id === null || $readState->last_read_message_id < $lastMessage->id)) {
                $readState->update(['last_read_message_id' => $lastMessage->id, 'read_at' => now()]);
            }

            // Dispatch broadcast after commit
            Dispatch::afterCommit(function () use ($conversation, $resolver, $readState, $lastMessage) {
                event(new SupportConversationResolved($conversation->fresh(['status', 'resolved_at', 'resolver']), $resolver));
                if ($lastMessage = $conversation->lastMessage) {
                    event(new SupportReadStateUpdated($conversation, $resolver, $lastMessage->id));
                }
            });

            return $conversation->fresh();
        });
    }
}
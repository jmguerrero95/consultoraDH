<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Events\SupportConversationCreated;
use App\Domain\Support\Events\SupportMessageCreated;
use App\Domain\Support\Events\SupportReadStateUpdated;
use App\Domain\Support\SupportMessageChannel;
use App\Domain\Support\SupportMessageKind;
use App\Domain\Support\SupportMessageSenderKind;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportReadState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Dispatch;

class AppendClientMessage
{
    public function execute(SupportConversation $conversation, string $bodyText, ?User $author = null, array $attachmentIds = []): SupportMessage
    {
        if (! $conversation->canReceiveClientMessage()) {
            throw new \DomainException('Conversation cannot receive client messages');
        }

        return DB::transaction(function () use ($conversation, $bodyText, $author, $attachmentIds) {
            // Re-read conversation under row lock to ensure authoritative state
            $conversation = SupportConversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $conversation->canReceiveClientMessage()) {
                throw new \DomainException('Conversation cannot receive client messages');
            }

            $message = SupportMessage::create([
                'conversation_id' => $conversation->id,
                'author_user_id' => $author?->id,
                'sender_kind' => SupportMessageSenderKind::Client,
                'message_kind' => SupportMessageKind::Message,
                'channel' => SupportMessageChannel::Portal,
                'body_text' => $bodyText,
                'client_visible' => true,
            ]);

            if (! empty($attachmentIds)) {
                SupportMessageAttachment::whereIn('id', $attachmentIds)
                    ->whereNull('support_message_id')
                    ->update(['support_message_id' => $message->id]);
            }

            $wasWaitingStaff = $conversation->isWaitingStaff();

            $conversation->status = $conversation->status->onClientMessage($wasWaitingStaff);
            $conversation->last_message_id = $message->id;
            $conversation->last_message_at = now();
            $conversation->save();

            // If this is the first client message and conversation was waiting_staff,
            // ensure first response SLA is set from queue config
            if ($wasWaitingStaff && $conversation->queue) {
                $this->scheduleFirstResponseSla($conversation);
            }

            // Update client's read state
            $readState = SupportReadState::firstOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => $author->id],
                ['last_read_message_id' => $message->id, 'read_at' => now()]
            );
            $readState->update(['last_read_message_id' => $message->id, 'read_at' => now()]);

            // Dispatch broadcast after commit
            DB::afterCommit(function () use ($message, $conversation, $author, $wasWaitingStaff) {
                event(new SupportMessageCreated($message->fresh(['author', 'attachments'])));
                event(new SupportReadStateUpdated($conversation, $author, $message->id));

                // If this is a new conversation (no previous client messages), also broadcast conversation created
                if ($wasWaitingStaff && $conversation->origin_channel === 'portal') {
                    event(new SupportConversationCreated($conversation->fresh(['client', 'queue']), $author));
                }
            });

            return $message->fresh(['attachments']);
        });
    }

    private function scheduleFirstResponseSla(SupportConversation $conversation): void
    {
        if ($conversation->queue->first_response_minutes) {
            $conversation->update([
                'first_response_due_at' => now()->addMinutes($conversation->queue->first_response_minutes),
            ]);
        }
    }
}
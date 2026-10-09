<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Events\SupportMessageCreated;
use App\Domain\Support\Events\SupportReadStateUpdated;
use App\Domain\Support\SupportMessageChannel;
use App\Domain\Support\SupportMessageKind;
use App\Domain\Support\SupportMessageSenderKind;
use App\Jobs\SendSupportFallbackEmail;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportReadState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Dispatch;

class AppendStaffReply
{
    public function execute(SupportConversation $conversation, string $bodyText, User $author, array $attachmentIds = []): SupportMessage
    {
        return DB::transaction(function () use ($conversation, $bodyText, $author, $attachmentIds) {
            $conversation->lockForUpdate();
            $conversation = $conversation->fresh();

            if ($conversation->isTerminal()) {
                throw new \DomainException('Cannot reply to a terminal conversation');
            }

            $message = SupportMessage::create([
                'conversation_id' => $conversation->id,
                'author_user_id' => $author->id,
                'sender_kind' => SupportMessageSenderKind::Staff,
                'message_kind' => SupportMessageKind::Message,
                'channel' => SupportMessageChannel::Staff,
                'body_text' => $bodyText,
                'client_visible' => true,
            ]);

            if (! empty($attachmentIds)) {
                SupportMessageAttachment::whereIn('id', $attachmentIds)
                    ->whereNull('support_message_id')
                    ->update(['support_message_id' => $message->id]);
            }

            $conversation->status = $conversation->status->onStaffClientVisibleReply();
            $conversation->last_message_id = $message->id;
            $conversation->last_message_at = now();
            $conversation->next_staff_response_due_at = null;
            $conversation->first_responded_at = $conversation->first_responded_at ?? now();
            $conversation->save();

            // Schedule next response SLA if queue has config
            if ($conversation->queue && $conversation->queue->next_response_minutes) {
                $conversation->update([
                    'next_staff_response_due_at' => now()->addMinutes($conversation->queue->next_response_minutes),
                ]);
            }

            // Clear unread for author
            $readState = SupportReadState::firstOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => $author->id],
                ['last_read_message_id' => $message->id, 'read_at' => now()]
            );
            if ($readState->last_read_message_id === null || $readState->last_read_message_id < $message->id) {
                $readState->update(['last_read_message_id' => $message->id, 'read_at' => now()]);
            }

            // Dispatch broadcast after commit
            Dispatch::afterCommit(function () use ($message, $conversation, $author) {
                event(new SupportMessageCreated($message->fresh(['author', 'attachments'])));
                event(new SupportReadStateUpdated($conversation, $author, $message->id));
            });

            // Schedule fallback email for client if offline
            $this->scheduleClientFallback($conversation, $message);

            return $message->fresh(['attachments']);
        });
    }

    private function scheduleClientFallback(SupportConversation $conversation, SupportMessage $message): void
    {
        if (! $conversation->client_id) {
            return;
        }

        $queue = $conversation->queue;
        $delay = $queue?->fallback_email_delay_minutes ?? config('support.default_fallback_delay_minutes', 5);

        SendSupportFallbackEmail::dispatch($message->id, $conversation->client_id)
            ->delay(now()->addMinutes($delay))
            ->onQueue('notifications');
    }
}

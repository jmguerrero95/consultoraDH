<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Events\SupportMessageCreated;
use App\Domain\Support\SupportMessageChannel;
use App\Domain\Support\SupportMessageKind;
use App\Domain\Support\SupportMessageSenderKind;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Dispatch;

class AddInternalNote
{
    public function execute(SupportConversation $conversation, string $bodyText, User $author, array $attachmentIds = []): SupportMessage
    {
        return DB::transaction(function () use ($conversation, $bodyText, $author, $attachmentIds) {
            // Re-read conversation under row lock to ensure authoritative state
            $conversation = SupportConversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($conversation->isTerminal()) {
                throw new \DomainException('Cannot add note to a terminal conversation');
            }

            $message = SupportMessage::create([
                'conversation_id' => $conversation->id,
                'author_user_id' => $author->id,
                'sender_kind' => SupportMessageSenderKind::Staff,
                'message_kind' => SupportMessageKind::Note,
                'channel' => SupportMessageChannel::Staff,
                'body_text' => $bodyText,
                'client_visible' => false,
            ]);

            if (! empty($attachmentIds)) {
                SupportMessageAttachment::whereIn('id', $attachmentIds)
                    ->whereNull('support_message_id')
                    ->update(['support_message_id' => $message->id]);
            }

            $conversation->last_message_id = $message->id;
            $conversation->last_message_at = now();
            $conversation->save();

            // Dispatch broadcast after commit (internal notes only to staff on conversation channel)
            DB::afterCommit(function () use ($message) {
                event(new SupportMessageCreated($message->fresh(['author', 'attachments'])));
            });

            return $message->fresh(['attachments']);
        });
    }
}
<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\SupportAttachmentRejected;
use App\Models\SupportConversation;
use App\Models\SupportMessageAttachment;
use App\Models\User;

/**
 * Links previously uploaded attachments to the message that sends them.
 *
 * ## The invariant this enforces
 *
 * An attachment belongs to exactly one conversation from the moment it is
 * uploaded (`conversation_id` is NOT NULL) and to exactly one message once it
 * is sent. Linking validates all of that in the same query that performs the
 * update: the rows must be in **this** conversation, must be **unsent**, and
 * must have been uploaded by **this** sender. A guessed id — from another
 * client, from another conversation of the same client, or from a colleague's
 * draft — resolves to zero rows and rejects the request.
 *
 * The caller runs this inside the message transaction, so a rejected id rolls
 * back the message too. Nothing is partially sent.
 */
final class LinkSupportAttachments
{
    /**
     * @param  list<int|string>  $attachmentIds
     *
     * @throws SupportAttachmentRejected
     */
    public function execute(
        SupportConversation $conversation,
        int $messageId,
        array $attachmentIds,
        User $uploader,
    ): void {
        $ids = array_values(array_unique(array_map('intval', $attachmentIds)));

        if ($ids === []) {
            return;
        }

        $attachments = SupportMessageAttachment::query()
            ->whereIn('id', $ids)
            ->where('conversation_id', $conversation->id)
            ->where('uploaded_by_user_id', $uploader->id)
            ->whereNull('support_message_id')
            ->lockForUpdate()
            ->get(['id']);

        if ($attachments->count() !== count($ids)) {
            throw new SupportAttachmentRejected;
        }

        SupportMessageAttachment::query()
            ->whereKey($attachments->pluck('id')->all())
            ->update(['support_message_id' => $messageId]);
    }
}

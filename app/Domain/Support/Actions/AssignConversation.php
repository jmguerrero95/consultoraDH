<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Events\SupportConversationAssigned;
use App\Models\SupportConversation;
use App\Models\SupportQueueMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Dispatch;

class AssignConversation
{
    public function execute(SupportConversation $conversation, User $assignee, User $actor): SupportConversation
    {
        if ($assignee->account_type !== 'staff' || !$assignee->status->is('active')) {
            throw new \InvalidArgumentException('Assignee must be an active staff user');
        }

        return DB::transaction(function () use ($conversation, $assignee, $actor) {
            // Re-read conversation under row lock to ensure authoritative state
            $conversation = SupportConversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($conversation->isTerminal()) {
                throw new \DomainException('Cannot assign a terminal conversation');
            }

            // Check queue membership unless actor has support.view_all
            if (!$actor->hasPermissionTo('support.view_all')) {
                $isMember = SupportQueueMember::where('queue_id', $conversation->queue_id)
                    ->where('user_id', $assignee->id)
                    ->exists();

                if (!$isMember) {
                    throw new \DomainException('Assignee is not a member of the conversation queue');
                }
            }

            $conversation->assigned_to_user_id = $assignee->id;
            $conversation->save();

            // Dispatch broadcast after commit
            Dispatch::afterCommit(function () use ($conversation, $assignee, $actor) {
                event(new SupportConversationAssigned($conversation->fresh(['assignee']), $assignee, $actor));
            });

            return $conversation->fresh(['assignee']);
        });
    }
}
<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Events\SupportConversationQueueChanged;
use App\Models\SupportConversation;
use App\Models\SupportQueue;
use App\Models\SupportQueueMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Dispatch;

class ChangeConversationQueue
{
    public function execute(SupportConversation $conversation, SupportQueue $newQueue, User $actor, ?User $newAssignee = null): SupportConversation
    {
        return DB::transaction(function () use ($conversation, $newQueue, $actor, $newAssignee) {
            // Re-read conversation under row lock to ensure authoritative state
            $conversation = SupportConversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($conversation->isTerminal()) {
                throw new \DomainException('Cannot change queue of a terminal conversation');
            }

            $currentAssignee = $conversation->assignee;
            $assigneeId = $newAssignee?->id;

            // If no new assignee provided, check if current assignee is member of new queue
            if (!$assigneeId && $currentAssignee) {
                $isMember = SupportQueueMember::where('queue_id', $newQueue->id)
                    ->where('user_id', $currentAssignee->id)
                    ->exists();

                if (!$isMember && !$actor->hasPermissionTo('support.view_all')) {
                    throw new \DomainException(
                        'Current assignee is not a member of the new queue. '.
                        'Provide a new valid assignee or ensure current assignee is a member.'
                    );
                }
                $assigneeId = $currentAssignee->id;
            }

            // If new assignee provided, validate
            if ($assigneeId && $newAssignee) {
                if ($newAssignee->account_type !== 'staff' || !$newAssignee->status->value === 'active') {
                    throw new \InvalidArgumentException('Assignee must be an active staff user');
                }

                $isMember = SupportQueueMember::where('queue_id', $newQueue->id)
                    ->where('user_id', $newAssignee->id)
                    ->exists();

                if (!$isMember && !$actor->hasPermissionTo('support.view_all')) {
                    throw new \DomainException('Assignee is not a member of the new queue');
                }
            }

            $conversation->queue_id = $newQueue->id;
            $conversation->assigned_to_user_id = $assigneeId;
            $conversation->save();

            // Dispatch broadcast after commit
            DB::afterCommit(function () use ($conversation, $actor, $newAssignee) {
                event(new SupportConversationQueueChanged($conversation->fresh(['queue', 'assignee']), $actor, $newAssignee));
            });

            return $conversation->fresh(['queue', 'assignee']);
        });
    }
}
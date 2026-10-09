<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Events\SupportConversationUpdated;
use App\Domain\Support\SupportConversationPriority;
use App\Models\SupportConversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Dispatch;

class ChangeConversationPriority
{
    public function execute(SupportConversation $conversation, SupportConversationPriority $priority): SupportConversation
    {
        return DB::transaction(function () use ($conversation, $priority) {
            $conversation->lockForUpdate();
            $conversation = $conversation->fresh();

            if ($conversation->isTerminal()) {
                throw new \DomainException('Cannot change priority of a terminal conversation');
            }

            $conversation->priority = $priority;
            $conversation->save();

            // Dispatch broadcast after commit
            Dispatch::afterCommit(function () use ($conversation) {
                event(new SupportConversationUpdated($conversation->fresh(['priority']), 'priority'));
            });

            return $conversation->fresh();
        });
    }
}

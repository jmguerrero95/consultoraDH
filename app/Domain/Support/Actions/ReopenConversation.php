<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Events\SupportConversationReopened;
use App\Domain\Support\SupportConversationStatus;
use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Dispatch;

class ReopenConversation
{
    public function execute(SupportConversation $conversation, User $actor): SupportConversation
    {
        return DB::transaction(function () use ($conversation, $actor) {
            $conversation->lockForUpdate();
            $conversation = $conversation->fresh();

            if (! $conversation->isResolved() && ! $conversation->isClosed()) {
                throw new \DomainException('Only resolved or closed conversations can be reopened');
            }

            $conversation->status = SupportConversationStatus::WaitingStaff;
            $conversation->resolved_at = null;
            $conversation->resolved_by = null;
            $conversation->closed_at = null;
            $conversation->closed_by = null;
            $conversation->save();

            // Dispatch broadcast after commit
            Dispatch::afterCommit(function () use ($conversation, $actor) {
                event(new SupportConversationReopened($conversation->fresh(['status', 'resolved_at', 'closed_at']), $actor));
            });

            return $conversation->fresh();
        });
    }
}

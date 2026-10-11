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
            // Re-read conversation under row lock to ensure authoritative state
            $conversation = SupportConversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($conversation->isTerminal()) {
                throw new \DomainException('Cannot change priority of a terminal conversation');
            }

            $conversation->priority = $priority;
            $conversation->save();

            // Dispatch broadcast after commit.
            //
            // `fresh()` takes relations to eager load, so passing 'priority'
            // asked for a relationship that does not exist — priority is a cast,
            // not a relation — and every priority change raised
            // RelationNotFoundException from inside the after-commit hook. The
            // plain re-read already carries the value that was just written.
            DB::afterCommit(function () use ($conversation) {
                event(new SupportConversationUpdated($conversation->fresh(), 'priority'));
            });

            return $conversation->fresh();
        });
    }
}

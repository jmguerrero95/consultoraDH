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
            // Re-read conversation under row lock to ensure authoritative state
            $conversation = SupportConversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$conversation->isResolved() && !$conversation->isClosed()) {
                throw new \DomainException('Only resolved or closed conversations can be reopened');
            }

            $conversation->status = SupportConversationStatus::WaitingStaff;
            $conversation->resolved_at = null;
            $conversation->resolved_by = null;
            $conversation->closed_at = null;
            $conversation->closed_by = null;
            $conversation->save();

            // Set first response SLA on reopen
            if ($conversation->queue && $conversation->queue->first_response_minutes) {
                $conversation->update([
                    'first_response_due_at' => now()->addMinutes($conversation->queue->first_response_minutes),
                    'next_staff_response_due_at' => null,
                    'resolution_due_at' => null,
                ]);
            }

            // Dispatch broadcast after commit
            DB::afterCommit(function () use ($conversation, $actor) {
                event(new SupportConversationReopened($conversation->fresh(['queue']), $actor));
            });

            return $conversation->fresh();
        });
    }
}
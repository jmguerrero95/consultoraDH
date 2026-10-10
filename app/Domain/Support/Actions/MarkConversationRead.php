<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Events\SupportReadStateUpdated;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportReadState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Dispatch;

class MarkConversationRead
{
    public function execute(SupportConversation $conversation, User $user, SupportMessage $upToMessage): array
    {
        if ($upToMessage->conversation_id !== $conversation->id) {
            throw new \InvalidArgumentException('Message does not belong to this conversation');
        }

        if (!$upToMessage->isClientVisible() && $user->account_type === 'client') {
            throw new \DomainException('Cannot mark internal note as read');
        }

        return DB::transaction(function () use ($conversation, $user, $upToMessage) {
            // Lock the read state row to ensure monotonic cursor advancement
            $readState = SupportReadState::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$readState) {
                $readState = SupportReadState::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'last_read_message_id' => null,
                    'read_at' => null,
                ]);
            }

            // Atomic monotonic advancement: only advance if the new message ID is greater
            if ($readState->last_read_message_id === null || $readState->last_read_message_id < $upToMessage->id) {
                $readState->update([
                    'last_read_message_id' => $upToMessage->id,
                    'read_at' => now(),
                ]);
            }

            // Dispatch broadcast after commit
            Dispatch::afterCommit(function () use ($conversation, $user, $upToMessage) {
                event(new SupportReadStateUpdated($conversation, $user, $upToMessage->id));
            });

            return [
                'conversation_unread' => $this->calculateConversationUnread($conversation, $user),
                'global_unread' => $this->calculateGlobalUnread($user),
            ];
        });
    }

    private function calculateConversationUnread(SupportConversation $conversation, User $user): int
    {
        $readState = SupportReadState::where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();

        $query = SupportMessage::where('conversation_id', $conversation->id)
            ->where('client_visible', true);

        if ($user->account_type === 'client') {
            $query->where('sender_kind', 'staff');
        }

        if ($readState && $readState->last_read_message_id) {
            $query->where('id', '>', $readState->last_read_message_id);
        }

        return $query->count();
    }

    private function calculateGlobalUnread(User $user): int
    {
        if ($user->account_type === 'client') {
            if (!$user->client_id) {
                return 0;
            }

            return SupportConversation::where('client_id', $user->client_id)
                ->where('status', '!=', 'closed')
                ->whereHas('messages', function ($q) {
                    $q->where('sender_kind', 'staff')
                        ->where('client_visible', true);
                })
                ->whereHas('readStates', function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->where(function ($sub) {
                            $sub->whereNull('last_read_message_id')
                                ->orWhereHas('conversation.messages', function ($msg) {
                                    $msg->where('id', '>', $msg->getModel()->getConnection()->raw('support_read_states.last_read_message_id'))
                                        ->where('client_visible', true)
                                        ->where('sender_kind', 'staff');
                                });
                        });
                }, '>', 0)
                ->count();
        }

        $query = SupportConversation::query()
            ->whereHas('queue', function ($q) use ($user) {
                if (!$user->hasPermissionTo('support.view_all')) {
                    $q->whereHas('members', function ($mq) use ($user) {
                        $mq->where('user_id', $user->id);
                    });
                }
            })
            ->where('status', '!=', 'closed')
            ->whereHas('messages', function ($q) {
                $q->where('client_visible', true);
            })
            ->whereHas('readStates', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->where(function ($sub) {
                        $sub->whereNull('last_read_message_id')
                            ->orWhereHas('conversation.messages', function ($msg) {
                                $msg->where('id', '>', $msg->getModel()->getConnection()->raw('support_read_states.last_read_message_id'))
                                    ->where('client_visible', true);
                            });
                    });
            }, '>', 0);

        return $query->count();
    }
}
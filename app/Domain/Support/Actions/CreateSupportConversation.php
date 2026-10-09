<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\SupportConversationPriority;
use App\Domain\Support\SupportConversationStatus;
use App\Domain\Support\SupportMessageChannel;
use App\Domain\Support\SupportMessageKind;
use App\Domain\Support\SupportMessageSenderKind;
use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportQueue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateSupportConversation
{
    public function execute(array $data, User $creator): SupportConversation
    {
        return DB::transaction(function () use ($data, $creator) {
            $client = null;
            if (! empty($data['client_id'])) {
                $client = Client::findOrFail($data['client_id']);
            }

            $queue = $data['queue_id'] ?? SupportQueue::where('is_default', true)
                ->where('active', true)
                ->value('id');

            if (! $queue) {
                throw new \InvalidArgumentException('No default queue available');
            }

            $conversation = SupportConversation::create([
                'client_id' => $client?->id,
                'queue_id' => $queue,
                'subject' => $data['subject'],
                'status' => SupportConversationStatus::WaitingStaff,
                'priority' => $data['priority'] ?? SupportConversationPriority::Normal,
                'origin_channel' => $data['origin_channel'] ?? SupportMessageChannel::Portal,
                'created_by_user_id' => $creator->id,
            ]);

            if (! empty($data['first_message'])) {
                $message = $conversation->messages()->create([
                    'author_user_id' => $creator->id,
                    'sender_kind' => $data['sender_kind'] ?? SupportMessageSenderKind::Client,
                    'message_kind' => SupportMessageKind::Message,
                    'channel' => $data['origin_channel'] ?? SupportMessageChannel::Portal,
                    'body_text' => $data['first_message'],
                    'client_visible' => true,
                ]);

                $conversation->update([
                    'last_message_id' => $message->id,
                    'last_message_at' => now(),
                ]);
            }

            return $conversation->fresh(['client', 'queue']);
        });
    }
}

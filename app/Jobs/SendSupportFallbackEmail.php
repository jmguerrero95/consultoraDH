<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Support\Events\SupportDeliveryCreated;
use App\Mail\SupportFallbackEmail;
use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportDelivery;
use App\Models\SupportMessage;
use App\Models\SupportReadState;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;

class SendSupportFallbackEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public function __construct(
        public int $supportMessageId,
        public int $clientId
    ) {}

    public function handle(): void
    {
        $message = SupportMessage::with(['conversation', 'conversation.queue'])->find($this->supportMessageId);

        if (! $message) {
            Log::warning('Support fallback email: message not found', ['message_id' => $this->supportMessageId]);

            return;
        }

        $conversation = $message->conversation;

        if (! $conversation || ! $conversation->client_id || $conversation->client_id !== $this->clientId) {
            Log::warning('Support fallback email: conversation/client mismatch', [
                'message_id' => $this->supportMessageId,
                'client_id' => $this->clientId,
            ]);

            return;
        }

        // Check if message is already read
        $client = Client::find($this->clientId);
        if (! $client || ! $client->user) {
            Log::warning('Support fallback email: client/user not found', ['client_id' => $this->clientId]);

            return;
        }

        $readState = SupportReadState::where('conversation_id', $conversation->id)
            ->where('user_id', $client->user->id)
            ->first();

        if ($readState && $readState->last_read_message_id && $readState->last_read_message_id >= $message->id) {
            // Already read, suppress delivery
            $this->recordDelivery($conversation, $message, $client->user->id, 'suppressed', 'already_read');

            return;
        }

        // Check if client is online via presence
        if ($this->isUserOnline($client->user->id)) {
            $this->recordDelivery($conversation, $message, $client->user->id, 'suppressed', 'user_online');

            return;
        }

        // Check if delivery already sent
        $dedupeKey = "support_fallback_{$message->id}_{$client->user->id}";
        if (SupportDelivery::where('dedupe_key', $dedupeKey)->where('status', 'sent')->exists()) {
            return;
        }

        // Send email
        try {
            Mail::to($client->user->email)
                ->queue(new SupportFallbackEmail($message, $conversation));

            $this->recordDelivery($conversation, $message, $client->user->id, 'sent', null, $dedupeKey);
        } catch (\Throwable $e) {
            Log::error('Support fallback email failed', [
                'message_id' => $message->id,
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
            $this->recordDelivery($conversation, $message, $client->user->id, 'failed', $e->getCode() ?? 'unknown', $dedupeKey);
            throw $e;
        }
    }

    private function isUserOnline(int $userId): bool
    {
        $key = "support:presence:{$userId}";
        $presence = Redis::get($key);

        if (! $presence) {
            return false;
        }

        $data = json_decode($presence, true);
        if (! $data || ! isset($data['last_heartbeat'])) {
            return false;
        }

        $lastHeartbeat = Carbon::parse($data['last_heartbeat']);

        return $lastHeartbeat->diffInSeconds(now()) < 120; // 2 minutes
    }

    private function recordDelivery(
        SupportConversation $conversation,
        SupportMessage $message,
        int $recipientUserId,
        string $status,
        ?string $errorCode = null,
        ?string $dedupeKey = null
    ): void {
        $dedupeKey = $dedupeKey ?? "support_fallback_{$message->id}_{$recipientUserId}";

        SupportDelivery::updateOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'support_message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'recipient_user_id' => $recipientUserId,
                'channel' => 'email',
                'status' => $status,
                'dedupe_key' => $dedupeKey,
                'attempts' => $this->attempts(),
                'sent_at' => $status === 'sent' ? now() : null,
                'suppressed_at' => $status === 'suppressed' ? now() : null,
                'last_error_code' => $errorCode,
            ]
        );

        // Dispatch delivery event for audit
        event(new SupportDeliveryCreated($conversation, $message, $recipientUserId, $status));
    }
}

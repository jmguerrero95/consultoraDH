<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SupportDelivery;
use App\Models\TelegramEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public function __construct(
        public int $endpointId,
        public string $message,
        public ?string $dedupeKey = null
    ) {}

    public function handle(): void
    {
        $endpoint = TelegramEndpoint::find($this->endpointId);

        if (! $endpoint || ! $endpoint->enabled) {
            Log::info('Telegram endpoint not found or disabled', ['endpoint_id' => $this->endpointId]);

            return;
        }

        $dedupeKey = $this->dedupeKey ?? "telegram_{$endpoint->id}_".hash('sha256', $this->message);

        // Check if already sent
        if (SupportDelivery::where('dedupe_key', $dedupeKey)->where('status', 'sent')->exists()) {
            Log::info('Telegram message already sent', ['dedupe_key' => $dedupeKey]);

            return;
        }

        $token = config('services.telegram.bot_token') ?? env('TELEGRAM_BOT_TOKEN');

        if (! $token) {
            Log::error('Telegram bot token not configured');

            return;
        }

        try {
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $endpoint->chat_id,
                    'text' => $this->message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if ($response->successful()) {
                SupportDelivery::create([
                    'channel' => 'telegram',
                    'status' => 'sent',
                    'dedupe_key' => $dedupeKey,
                    'sent_at' => now(),
                ]);
                Log::info('Telegram message sent', ['endpoint_id' => $endpoint->id]);
            } else {
                throw new \Exception('Telegram API error: '.$response->body());
            }
        } catch (\Throwable $e) {
            Log::error('Telegram message failed', [
                'endpoint_id' => $endpoint->id,
                'error' => $e->getMessage(),
            ]);

            SupportDelivery::create([
                'channel' => 'telegram',
                'status' => 'failed',
                'dedupe_key' => $dedupeKey,
                'attempts' => $this->attempts(),
                'last_error_code' => $e->getCode() ?? 'unknown',
            ]);

            throw $e;
        }
    }
}

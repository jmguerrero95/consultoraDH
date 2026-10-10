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
use Illuminate\Support\Facades\DB;
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

        // Claim the key before sending, not after. Asking whether a 'sent' row
        // exists and only then inserting is a check-then-act race: two workers
        // running the same escalation both read "not sent yet" and both post to
        // the chat. The unique index on dedupe_key is what actually decides the
        // winner, so the claim has to be the insert itself.
        $delivery = $this->claimDedupeKey($dedupeKey);

        if ($delivery === null) {
            Log::info('Telegram message already delivered or in flight', ['dedupe_key' => $dedupeKey]);

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
                // Update the row this worker claimed rather than inserting a
                // second one: the unique index would refuse it anyway, and the
                // failure would be reported as a broken send.
                $delivery->update(['status' => 'sent', 'sent_at' => now()]);
                Log::info('Telegram message sent', ['endpoint_id' => $endpoint->id]);
            } else {
                throw new \Exception('Telegram API error: '.$response->body());
            }
        } catch (\Throwable $e) {
            Log::error('Telegram message failed', [
                'endpoint_id' => $endpoint->id,
                'error' => $e->getMessage(),
            ]);

            $delivery->update([
                'status' => 'failed',
                'last_error_code' => $e->getCode() ?: 'unknown',
            ]);

            throw $e;
        }
    }

    /**
     * Take exclusive ownership of a dedupe key, or report that someone else has it.
     *
     * A previous failure is handed back to the next attempt: inserting a fresh
     * row would collide on the unique index and turn every retry into a
     * permanent error.
     *
     * The claim is `ON CONFLICT DO NOTHING` rather than a try/catch around a
     * plain insert. Letting the insert raise and catching it works on its own,
     * but in PostgreSQL a failed statement aborts the surrounding transaction,
     * so every later query in that transaction fails too. Asking the database
     * to decline the insert keeps the transaction usable either way.
     */
    private function claimDedupeKey(string $dedupeKey): ?SupportDelivery
    {
        $retried = SupportDelivery::query()
            ->where('dedupe_key', $dedupeKey)
            ->where('status', 'failed')
            ->update([
                'status' => 'pending',
                'attempts' => DB::raw('support_deliveries.attempts + 1'),
                'last_error_code' => null,
            ]);

        if ($retried > 0) {
            return SupportDelivery::where('dedupe_key', $dedupeKey)->first();
        }

        $inserted = DB::table('support_deliveries')->insertOrIgnore([
            'channel' => 'telegram',
            'status' => 'pending',
            'dedupe_key' => $dedupeKey,
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            // Either already delivered, or another worker is sending it right now.
            return null;
        }

        return SupportDelivery::where('dedupe_key', $dedupeKey)->first();
    }
}

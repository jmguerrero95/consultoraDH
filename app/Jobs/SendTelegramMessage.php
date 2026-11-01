<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Automations\AutomationRunStatus;
use App\Models\AutomationActionRun;
use App\Models\SupportDelivery;
use App\Models\TelegramEndpoint;
use App\Services\Integrations\IntegrationConfig;
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

    /**
     * How long a `pending` delivery is honoured before it is considered
     * abandoned.
     *
     * The row is claimed before the message leaves, but the claim itself only
     * means "this worker intends to send". A worker that dies in that window
     * leaves the row `pending`, and nothing else would ever move it: the retry
     * path only takes over rows that are `failed`. The delivery would stay
     * `pending` for ever and the dedupe key would refuse every future send
     * with that key.
     *
     * The window is the job's own timeout plus a margin, so a worker that is
     * still preparing the request is never robbed of its claim.
     *
     * ## Why the age alone is not evidence of "nothing was sent"
     *
     * A stale `pending` row proves that no HTTP call was started: the status is
     * moved to `sending` immediately before the request, and that transition is
     * a conditional update, so only one worker can make it. A stale `pending`
     * row is therefore safe to reclaim, while a stale `sending` row is not —
     * the call may have reached Telegram and the effect may exist. Those rows
     * become `unknown` and are never resent automatically; they stay visible
     * for reconciliation.
     */
    public const ABANDONED_AFTER_SECONDS = 60;

    /** Queued for this worker; no external effect has been started. */
    private const STATUS_PENDING = 'pending';

    /** The request has been started (or is about to be); the effect may exist. */
    private const STATUS_SENDING = 'sending';

    private const STATUS_SENT = 'sent';

    private const STATUS_FAILED = 'failed';

    private const STATUS_UNKNOWN = 'unknown';

    private const STATUS_SUPPRESSED = 'suppressed';

    public function __construct(
        public int $endpointId,
        public string $message,
        public ?string $dedupeKey = null,
        public ?int $automationActionRunId = null,
    ) {}

    public function handle(IntegrationConfig $integrations): void
    {
        $endpoint = TelegramEndpoint::find($this->endpointId);

        if (! $endpoint || ! $endpoint->enabled) {
            Log::info('Telegram endpoint not found or disabled', ['endpoint_id' => $this->endpointId]);
            $this->recordOutcome('failed', 'telegram_endpoint_unavailable');

            return;
        }

        // The configuration is checked **before** the claim.
        //
        // The claim used to come first, so a missing token left a `pending`
        // delivery row behind: the retry path only takes over rows that are
        // `failed`, and the unique index refused a second row with the same
        // key. The delivery stayed `pending` for ever and every future send
        // with that key was declined as "already in flight".
        //
        // The resolver reads the stored credential first and the environment
        // second, so a token changed from the panel is the one this worker uses
        // without a restart.
        $token = $integrations->telegramBotToken();

        if (! $token) {
            Log::error('Telegram bot token not configured');
            $this->recordOutcome('failed', 'telegram_token_missing');

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

            // Already sent: idempotently a success. Still in flight: not ours to
            // declare, so the action stays queued until that worker reports. An
            // uncertain outcome is neither: it stays for a human to reconcile.
            $existing = SupportDelivery::query()->where('dedupe_key', $dedupeKey)->value('status');
            $this->recordOutcome(
                $existing === self::STATUS_SENT ? 'succeeded' : ($existing === self::STATUS_UNKNOWN ? 'failed' : 'queued'),
                $existing === self::STATUS_SENT ? null : ($existing === self::STATUS_UNKNOWN ? 'telegram_outcome_unknown' : 'delivery_in_flight'),
            );

            return;
        }

        // The row is ours, but the send only starts when this conditional
        // update moves it from `pending` to `sending`. Two workers can both
        // hold a row after a stale reclaim, and only one can win this update:
        // the loser must not post the same message.
        if (! $this->beginTransport($delivery)) {
            Log::info('Telegram delivery was taken by another worker', ['dedupe_key' => $dedupeKey]);

            $this->recordOutcome('queued', 'delivery_in_flight');

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
        } catch (\Throwable $e) {
            // The request may have reached Telegram before the connection
            // broke. Repeating it could post the message twice, so the row is
            // recorded as unknown and the job does not retry.
            $this->settle($delivery, self::STATUS_UNKNOWN, 'telegram_outcome_unknown');
            $this->recordOutcome('failed', 'telegram_outcome_unknown');

            Log::error('Telegram message outcome unknown after a transport failure', [
                'endpoint_id' => $endpoint->id,
                'error_class' => $e::class,
            ]);

            $this->fail($e);

            return;
        }

        if ($response->successful()) {
            // Update the row this worker claimed rather than inserting a second
            // one: the unique index would refuse it anyway, and the failure
            // would be reported as a broken send.
            $this->settle($delivery, self::STATUS_SENT, null, sent: true);
            Log::info('Telegram message sent', ['endpoint_id' => $endpoint->id]);
            $this->recordOutcome('succeeded');

            return;
        }

        $status = $response->status();
        $errorCode = $this->errorCode($response->json());

        // A 4xx other than 429 is a definite refusal: the same message will be
        // refused again, so it is recorded as suppressed instead of retried.
        if ($status >= 400 && $status < 500 && $status !== 429) {
            $this->settle($delivery, self::STATUS_SUPPRESSED, 'telegram_rejected_'.$status);
            $this->recordOutcome('failed', 'telegram_rejected');

            Log::warning('Telegram message rejected by the provider', [
                'endpoint_id' => $endpoint->id,
                'http_status' => $status,
                'telegram_error_code' => $errorCode,
            ]);

            $this->fail(new \RuntimeException('Telegram rejected the message ('.$status.').'));

            return;
        }

        if ($status === 429) {
            // "Too many requests" says the message was not accepted; waiting and
            // trying the same delivery again is safe and is what the provider
            // asks for.
            $this->settle($delivery, self::STATUS_FAILED, 'telegram_rate_limited');
            $this->recordOutcome('failed', 'telegram_rate_limited');

            Log::warning('Telegram rate limited the message', ['endpoint_id' => $endpoint->id]);

            throw new \RuntimeException('Telegram rate limited the message.');
        }

        // A 5xx does not say whether the effect happened. Retrying blindly can
        // duplicate it, so the row becomes `unknown` and no automatic retry runs.
        $this->settle($delivery, self::STATUS_UNKNOWN, 'telegram_http_'.$status);
        $this->recordOutcome('failed', 'telegram_outcome_unknown');

        Log::error('Telegram message outcome unknown after a provider error', [
            'endpoint_id' => $endpoint->id,
            'http_status' => $status,
            'telegram_error_code' => $errorCode,
        ]);

        $this->fail(new \RuntimeException('Telegram outcome unknown ('.$status.').'));
    }

    private function errorCode(mixed $json): ?int
    {
        if (! is_array($json)) {
            return null;
        }

        return isset($json['error_code']) && is_int($json['error_code']) ? $json['error_code'] : null;
    }

    /**
     * Move the claimed row to its real outcome.
     */
    private function settle(
        SupportDelivery $delivery,
        string $status,
        ?string $errorCode = null,
        bool $sent = false,
    ): void {
        $delivery->update([
            'status' => $status,
            'last_error_code' => $errorCode,
            'sent_at' => $sent ? now() : $delivery->sent_at,
            'suppressed_at' => $status === self::STATUS_SUPPRESSED ? now() : $delivery->suppressed_at,
        ]);
    }

    /**
     * Report the real result to the automation action that asked for the send.
     */
    private function recordOutcome(string $status, ?string $errorCode = null): void
    {
        if ($this->automationActionRunId === null) {
            return;
        }

        AutomationActionRun::query()
            ->whereKey($this->automationActionRunId)
            ->update([
                'status' => $status,
                'error_code' => $errorCode,
                'finished_at' => $status === 'queued' ? null : now(),
            ]);

        AutomationRunStatus::recomputeForActionRun($this->automationActionRunId);
    }

    /**
     * Move this worker's claim from `pending` to `sending`, or report that
     * somebody else already did.
     *
     * This is the boundary that makes "the row is old" an insufficient reason
     * to resend. Everything before this update is local bookkeeping; from this
     * point on, Telegram may have received the message. The update is
     * conditional on the row still being `pending`, so of two workers holding
     * the same reclaimed row exactly one starts the request.
     *
     * `attempts` counts transports actually started, not claims taken. The
     * counter is bumped here, once, and never while merely reading or queuing.
     */
    private function beginTransport(SupportDelivery $delivery): bool
    {
        $started = SupportDelivery::query()
            ->whereKey($delivery->id)
            ->where('status', self::STATUS_PENDING)
            ->update([
                'status' => self::STATUS_SENDING,
                'attempts' => DB::raw('support_deliveries.attempts + 1'),
                'last_error_code' => null,
                'updated_at' => now(),
            ]);

        return $started === 1;
    }

    /**
     * Take exclusive ownership of a dedupe key, or report that someone else has it.
     *
     * A previous *retryable* failure is handed back to the next attempt:
     * inserting a fresh row would collide on the unique index and turn every
     * retry into a permanent error. A `suppressed` row is a definite refusal,
     * an `unknown` row is an outcome nobody has established, and a `sending`
     * row may already have produced the effect, so none of them is reclaimed
     * automatically.
     *
     * The claim is `ON CONFLICT DO NOTHING` rather than a try/catch around a
     * plain insert. Letting the insert raise and catching it works on its own,
     * but in PostgreSQL a failed statement aborts the surrounding transaction,
     * so every later query in that transaction fails too. Asking the database
     * to decline the insert keeps the transaction usable either way.
     */
    private function claimDedupeKey(string $dedupeKey): ?SupportDelivery
    {
        $abandonedBefore = now()->subSeconds(self::ABANDONED_AFTER_SECONDS);

        // A stale `sending` row is not evidence that nothing was sent: the
        // request was started before the status moved. Telegram may have
        // delivered it, so the row is settled as `unknown` — observable and
        // reconcilable — and never resent by this path.
        SupportDelivery::query()
            ->where('dedupe_key', $dedupeKey)
            ->where('status', self::STATUS_SENDING)
            ->where('updated_at', '<', $abandonedBefore)
            ->update([
                'status' => self::STATUS_UNKNOWN,
                'last_error_code' => 'telegram_outcome_unknown',
                'updated_at' => now(),
            ]);

        // An abandoned claim: a `pending` row whose worker is gone before the
        // transport started. That is the only phase where the age proves that
        // no external effect exists, so it is the only one that is reclaimed.
        // The update is conditional, so two workers cannot both take it.
        $reclaimed = SupportDelivery::query()
            ->where('dedupe_key', $dedupeKey)
            ->where('status', self::STATUS_PENDING)
            ->where('updated_at', '<', $abandonedBefore)
            ->update([
                'status' => self::STATUS_PENDING,
                'last_error_code' => null,
                'updated_at' => now(),
            ]);

        if ($reclaimed > 0) {
            return SupportDelivery::where('dedupe_key', $dedupeKey)->first();
        }

        // A retryable failure (429) is safe to attempt again. The attempt is
        // counted when the transport starts, not here.
        $retried = SupportDelivery::query()
            ->where('dedupe_key', $dedupeKey)
            ->where('status', self::STATUS_FAILED)
            ->update([
                'status' => self::STATUS_PENDING,
                'last_error_code' => null,
                'updated_at' => now(),
            ]);

        if ($retried > 0) {
            return SupportDelivery::where('dedupe_key', $dedupeKey)->first();
        }

        $inserted = DB::table('support_deliveries')->insertOrIgnore([
            'channel' => 'telegram',
            'status' => self::STATUS_PENDING,
            'dedupe_key' => $dedupeKey,
            'attempts' => 0,
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

<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendTelegramMessage;
use App\Models\TelegramEndpoint;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one alert to the Telegram destinations that asked for it.
 *
 * ## One place builds the recipient list
 *
 * An event preference is only meaningful if the same query decides every
 * delivery. The SLA listener, the automation runner, the overdue-document scan
 * and the reminder dispatcher all come through here, so "who receives an
 * automation alert" has one answer rather than four slightly different ones.
 *
 * ## What a failure can and cannot do
 *
 * Dispatching is wrapped: a queue that is down must not roll back the task
 * completion, the reminder, or the report that produced the alert. The message
 * is queued, not sent here; the delivery ledger and the worker decide the real
 * outcome.
 */
final class TelegramNotifier
{
    /**
     * Send an event to every enabled administrative destination subscribed to
     * it, optionally narrowed to one support conversation.
     *
     * @return int Number of destinations the message was queued for.
     */
    public function notifyEvent(
        string $event,
        string $message,
        ?int $conversationId = null,
        string $dedupeBase = '',
    ): int {
        if (! TelegramEventCatalog::has($event)) {
            return 0;
        }

        $endpoints = TelegramEndpoint::query()
            ->where('enabled', true)
            ->whereNull('user_id')
            ->whereJsonContains('event_preferences', $event)
            ->when($conversationId !== null, function ($query) use ($conversationId): void {
                $query->where(function ($scope) use ($conversationId): void {
                    $scope->whereNull('conversation_id')->orWhere('conversation_id', $conversationId);
                });
            })
            ->when($conversationId === null, fn ($query) => $query->whereNull('conversation_id'))
            ->get();

        $queued = 0;

        foreach ($endpoints as $endpoint) {
            if ($this->dispatch($endpoint, $message, $event, $dedupeBase)) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Send to the personal chat bound to one employee.
     *
     * The binding is the authorisation: a chat that is not bound to an active
     * employee is not a delivery destination, even when the row exists.
     */
    public function notifyPersonal(
        User $user,
        string $event,
        string $message,
        string $dedupeBase,
    ): bool {
        $endpoint = TelegramEndpoint::query()
            ->where('user_id', $user->id)
            ->where('enabled', true)
            ->first();

        if ($endpoint === null) {
            return false;
        }

        return $this->dispatch($endpoint, $message, $event, $dedupeBase);
    }

    /**
     * Send to a personal chat only when the employee enabled the preference.
     */
    public function notifyPersonalIfEnabled(
        User $user,
        string $preference,
        string $event,
        string $message,
        string $dedupeBase,
    ): bool {
        if (! $user->notificationPreference($preference)) {
            return false;
        }

        return $this->notifyPersonal($user, $event, $message, $dedupeBase);
    }

    private function dispatch(
        TelegramEndpoint $endpoint,
        string $message,
        string $event,
        string $dedupeBase,
    ): bool {
        $dedupeKey = $this->dedupeKey($event, $endpoint->id, $dedupeBase);

        try {
            SendTelegramMessage::dispatch($endpoint->id, $message, $dedupeKey);

            return true;
        } catch (\Throwable $e) {
            // The business action that produced this alert already committed.
            // Telegram is an additional channel, never a reason to undo it.
            Log::warning('Telegram alert could not be queued', [
                'event' => $event,
                'endpoint_id' => $endpoint->id,
                'error_class' => $e::class,
            ]);

            return false;
        }
    }

    private function dedupeKey(string $event, int $endpointId, string $dedupeBase): string
    {
        // The key is what the delivery ledger enforces, so it must be stable
        // across retries and distinct between two legitimate events with the
        // same text. The caller supplies the event identity, never the body.
        return 'telegram:'.$event.':'.$endpointId.':'.hash('sha256', $dedupeBase === '' ? $event : $dedupeBase);
    }
}

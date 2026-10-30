<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Integrations\IntegrationConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The small, safe surface of the Telegram Bot API that administration needs.
 *
 * It exists so the controller never builds a URL with the token in it and never
 * handles the provider's response body. Every method returns only the public
 * fields the screen needs, and every failure is a {@see TelegramApiException}
 * that names the operation and the numeric code.
 */
final class TelegramApiClient
{
    private const TIMEOUT_SECONDS = 8;

    public function __construct(private readonly IntegrationConfig $config) {}

    public function configured(): bool
    {
        return $this->config->telegramBotToken() !== null;
    }

    /**
     * @return array{id: int, username: string|null, first_name: string|null}
     */
    public function getMe(): array
    {
        return $this->normaliseBot($this->call('getMe'));
    }

    /**
     * Verify a token the operator has not saved yet.
     *
     * Registration must not write a credential that has never answered. This
     * probe uses the candidate token directly and stores nothing.
     *
     * @return array{id: int, username: string|null, first_name: string|null}
     */
    public function getMeWithToken(string $token): array
    {
        return $this->normaliseBot($this->callWithToken($token, 'getMe'));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{id: int, username: string|null, first_name: string|null}
     */
    private function normaliseBot(array $result): array
    {
        return [
            'id' => (int) ($result['id'] ?? 0),
            'username' => is_string($result['username'] ?? null) ? $result['username'] : null,
            'first_name' => is_string($result['first_name'] ?? null) ? $result['first_name'] : null,
        ];
    }

    /**
     * @return array{url: string|null, pending_updates: int|null, last_error_date: int|null, last_error_message: string|null, ip: string|null}
     */
    public function getWebhookInfo(): array
    {
        $result = $this->call('getWebhookInfo');

        return [
            'url' => is_string($result['url'] ?? null) && $result['url'] !== '' ? $result['url'] : null,
            'pending_updates' => isset($result['pending_update_count']) ? (int) $result['pending_update_count'] : null,
            'last_error_date' => isset($result['last_error_date']) ? (int) $result['last_error_date'] : null,
            // Telegram's own message about the delivery problem. It can name the
            // endpoint and the status code, not the content of an update, but it
            // is only shown to an authorised administrator.
            'last_error_message' => is_string($result['last_error_message'] ?? null)
                ? $result['last_error_message']
                : null,
            'ip' => null,
        ];
    }

    public function setWebhook(string $url, string $secret): void
    {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook', [
            // Pending updates are the ones Telegram already accepted. Dropping
            // them would lose a message the user already sent, so they are kept
            // for the next webhook registration to deliver.
            'drop_pending_updates' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $operation, array $payload = []): array
    {
        $token = $this->config->telegramBotToken();

        if ($token === null) {
            throw new TelegramApiException($operation);
        }

        return $this->callWithToken($token, $operation, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function callWithToken(string $token, string $operation, array $payload = []): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/{$operation}", $payload);
        } catch (ConnectionException $e) {
            throw new TelegramApiException($operation);
        }

        $json = $response->json();

        if (! $response->successful() || ! is_array($json) || ($json['ok'] ?? false) !== true) {
            $errorCode = is_array($json) && is_int($json['error_code'] ?? null)
                ? $json['error_code']
                : null;

            throw new TelegramApiException(
                $operation,
                $response->status(),
                $errorCode,
                reachedTelegram: true,
            );
        }

        $result = $json['result'] ?? [];

        return is_array($result) ? $result : [];
    }
}

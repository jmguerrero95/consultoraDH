<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use RuntimeException;

/**
 * A Telegram API call failed.
 *
 * The message deliberately carries the operation, the HTTP status and
 * Telegram's numeric `error_code` — never the response body. A failed
 * `sendMessage` body can quote the text that was being sent, and a token can
 * appear in a URL that a transport exception prints. Those belong in neither a
 * log nor an administrative screen.
 */
final class TelegramApiException extends RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly int $httpStatus = 0,
        public readonly ?int $telegramErrorCode = null,
        public readonly bool $reachedTelegram = false,
    ) {
        parent::__construct(sprintf(
            'Telegram %s failed%s%s',
            $operation,
            $httpStatus > 0 ? " (HTTP {$httpStatus})" : '',
            $telegramErrorCode !== null ? " (error {$telegramErrorCode})" : '',
        ));
    }

    /**
     * A 4xx that will not change by repeating the same call.
     */
    public function isPermanentRejection(): bool
    {
        return $this->reachedTelegram
            && $this->httpStatus >= 400
            && $this->httpStatus < 500
            && $this->httpStatus !== 429;
    }
}

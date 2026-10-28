<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use RuntimeException;

/**
 * An outbound Telegram call failed after it was durably marked as started.
 *
 * The caller must not replay the update automatically: Telegram may have
 * accepted the message or document before the connection failed.
 */
final class TelegramExternalEffectFailed extends RuntimeException {}

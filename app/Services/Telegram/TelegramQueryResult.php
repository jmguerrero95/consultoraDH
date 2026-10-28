<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * One answered query and the memory it leaves behind.
 *
 * `context` is a patch, not the full endpoint context: a query that found one
 * client records that client, a query that found none records nothing and
 * leaves the previous subject intact. It never contains message text.
 */
final readonly class TelegramQueryResult
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $text,
        public array $context = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramUpdate;
use Illuminate\Console\Command;

/**
 * Remove Telegram updates past the idempotency retention window.
 *
 * This used to run inside the webhook, every hundredth update. That placed a
 * DELETE over a growing table on the response path of a request Telegram is
 * waiting for: one slow prune became a webhook timeout, and a timeout is
 * re-delivered. The webhook now only claims and processes its own update; this
 * command does the pruning on a schedule, where a slow DELETE costs nobody a
 * response.
 *
 * The retention period itself is unchanged (`TelegramUpdate::RETENTION_DAYS`).
 */
final class PruneTelegramUpdates extends Command
{
    protected $signature = 'telegram:prune-updates';

    protected $description = 'Delete Telegram update rows older than the retention window.';

    public function handle(): int
    {
        $deleted = TelegramUpdate::query()
            ->where('created_at', '<', now()->subDays(TelegramUpdate::RETENTION_DAYS))
            ->delete();

        $this->info("{$deleted} Telegram update(s) removed.");

        return self::SUCCESS;
    }
}

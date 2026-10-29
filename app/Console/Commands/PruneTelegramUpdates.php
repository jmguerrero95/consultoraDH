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
 * ## Only settled states are pruned (PERF-01-R1)
 *
 * Age alone is not a reason to forget a row. A row is the webhook's memory of
 * what happened to one update, and some outcomes are still needed after the
 * retention window:
 *
 *  - `failed_external` means an outbound effect may already have reached
 *    Telegram. It is pending reconciliation: deleting it would turn "we do not
 *    know" into "nothing happened", which is exactly the replay the idempotency
 *    table exists to prevent. These rows are never pruned.
 *  - `pending` is a claim that has not settled. An old one is a stuck worker to
 *    investigate, not garbage. These rows are never pruned either.
 *  - `succeeded` and `failed_recoverable` are the safe terminal states: the
 *    first was fully processed, the second provably stopped before any external
 *    effect (`external_started_at` is null). Once Telegram's retry window has
 *    passed, neither can be delivered again, so they can be forgotten.
 *
 * Deletion is batched so a first run on a large table cannot hold one long
 * transaction or lock the table for the webhook's inserts.
 *
 * The retention period itself is unchanged (`TelegramUpdate::RETENTION_DAYS`).
 */
final class PruneTelegramUpdates extends Command
{
    /**
     * States whose outcome is settled and carries no pending reconciliation.
     *
     * @var list<string>
     */
    private const PRUNABLE_STATES = [
        TelegramUpdate::STATE_SUCCEEDED,
        TelegramUpdate::STATE_FAILED_RECOVERABLE,
    ];

    protected $signature = 'telegram:prune-updates
        {--days= : Retention window in days (defaults to TelegramUpdate::RETENTION_DAYS)}
        {--chunk=1000 : Maximum rows deleted per statement}';

    protected $description = 'Delete settled Telegram update rows older than the retention window.';

    public function handle(): int
    {
        $days = $this->option('days');
        $retentionDays = is_numeric($days)
            ? max(0, (int) $days)
            : TelegramUpdate::RETENTION_DAYS;

        $chunkOption = $this->option('chunk');
        $chunk = is_numeric($chunkOption) ? max(1, (int) $chunkOption) : 1000;

        $cutoff = now()->subDays($retentionDays);
        $deleted = 0;

        // Bounded batches. The guard is a safety net, not the normal exit: the
        // loop ends when a batch comes back short, which means nothing eligible
        // is left. Held rows never enter the batch, so they cannot make it spin.
        for ($batch = 0; $batch < 1000; $batch++) {
            $ids = TelegramUpdate::query()
                ->whereIn('state', self::PRUNABLE_STATES)
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += TelegramUpdate::query()->whereIn('id', $ids)->delete();

            if ($ids->count() < $chunk) {
                break;
            }
        }

        $held = TelegramUpdate::query()
            ->whereIn('state', [
                TelegramUpdate::STATE_FAILED_EXTERNAL,
                TelegramUpdate::STATE_PENDING,
            ])
            ->where('created_at', '<', $cutoff)
            ->count();

        $this->info("{$deleted} Telegram update(s) removed.");

        if ($held > 0) {
            $this->line("{$held} row(s) older than the window kept for reconciliation or investigation.");
        }

        return self::SUCCESS;
    }
}

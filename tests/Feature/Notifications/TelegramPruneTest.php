<?php

declare(strict_types=1);

use App\Models\TelegramUpdate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PERF-01-R1 — pruning must forget only outcomes that cannot matter again.
 *
 * The row is the webhook's idempotency memory. `succeeded` is finished and
 * `failed_recoverable` provably stopped before any external effect; after
 * Telegram's retry window neither can be delivered again. `failed_external`
 * means an effect may already have reached Telegram and still needs
 * reconciliation, and `pending` is an unsettled claim to investigate. Those two
 * survive the retention window.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-28 12:00:00', 'UTC'));
});

function telegramUpdateRow(int $updateId, string $state, ?Carbon $createdAt = null): void
{
    DB::table('telegram_updates')->insert([
        'update_id' => $updateId,
        'chat_id' => 'qa-chat',
        'state' => $state,
        'claim_token' => null,
        'claimed_at' => null,
        'external_started_at' => $state === 'failed_external' ? now()->subDays(8) : null,
        'attempt_count' => 1,
        'failure_code' => null,
        'processed_at' => null,
        'created_at' => $createdAt ?? now()->subDay(),
        'updated_at' => $createdAt ?? now()->subDay(),
    ]);
}

it('removes settled rows and keeps reconciliation or investigation rows', function (): void {
    $old = now()->subDays(10);

    telegramUpdateRow(1, TelegramUpdate::STATE_SUCCEEDED, $old);
    telegramUpdateRow(2, TelegramUpdate::STATE_FAILED_RECOVERABLE, $old);
    telegramUpdateRow(3, TelegramUpdate::STATE_FAILED_EXTERNAL, $old);
    telegramUpdateRow(4, TelegramUpdate::STATE_PENDING, $old);
    telegramUpdateRow(5, TelegramUpdate::STATE_SUCCEEDED, now()->subHour());

    $this->artisan('telegram:prune-updates')->assertExitCode(0);

    expect(TelegramUpdate::query()->orderBy('update_id')->pluck('update_id')->all())
        ->toBe([3, 4, 5]);
});

it('keeps failed_external and pending even when the window is zero days', function (): void {
    telegramUpdateRow(10, TelegramUpdate::STATE_FAILED_EXTERNAL, now()->subDays(30));
    telegramUpdateRow(11, TelegramUpdate::STATE_PENDING, now()->subDays(30));
    telegramUpdateRow(12, TelegramUpdate::STATE_SUCCEEDED, now()->subDays(30));

    $this->artisan('telegram:prune-updates', ['--days' => 0])->assertExitCode(0);

    expect(TelegramUpdate::query()->orderBy('update_id')->pluck('update_id')->all())
        ->toBe([10, 11]);
});

it('deletes in bounded batches until no settled row is left', function (): void {
    foreach (range(20, 27) as $updateId) {
        telegramUpdateRow($updateId, TelegramUpdate::STATE_SUCCEEDED, now()->subDays(9));
    }

    $this->artisan('telegram:prune-updates', ['--chunk' => 3])->assertExitCode(0);

    expect(TelegramUpdate::query()->count())->toBe(0);
});

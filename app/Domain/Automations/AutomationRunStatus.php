<?php

declare(strict_types=1);

namespace App\Domain\Automations;

use App\Models\AutomationActionRun;
use App\Models\AutomationRun;

/**
 * Recomputes the state of a run from its action runs.
 *
 * A run whose email or Telegram action has only been *queued* is not finished.
 * The delivery job calls this after it succeeds or fails, so the run reaches a
 * terminal state when the last asynchronous action does — not when the
 * dispatcher returns.
 *
 * ## The state is derived, never asserted
 *
 * The run's status is always computed from the current state of its actions.
 * Nothing writes a terminal state on a run whose actions disagree with it, so
 * a delivery that finishes late cannot turn a failed run into a successful one
 * or the other way round.
 */
final class AutomationRunStatus
{
    /** Statuses that mean "the effect has not happened (yet)". */
    private const IN_FLIGHT = ['pending', 'running', 'queued'];

    public static function recomputeForActionRun(int $actionRunId): void
    {
        $runId = AutomationActionRun::query()->whereKey($actionRunId)->value('automation_run_id');

        if ($runId !== null) {
            self::recompute((int) $runId);
        }
    }

    public static function recompute(int $runId): void
    {
        $run = AutomationRun::query()->find($runId);

        if ($run === null || $run->status === 'blocked') {
            return;
        }

        $statuses = $run->actionRuns()->pluck('status')->all();

        if (array_intersect($statuses, self::IN_FLIGHT) !== []) {
            // Still waiting on a worker. The run is neither a success nor a
            // failure yet, and saying either would be a guess.
            // The builder writes unconditionally: the in-memory model was
            // loaded before the actions changed, and an update that looks
            // identical to the stale attributes performs no statement at all.
            AutomationRun::query()
                ->whereKey($runId)
                ->update(['status' => 'queued', 'finished_at' => null]);

            return;
        }

        $failed = array_intersect($statuses, ['failed', 'blocked']) !== [];

        AutomationRun::query()
            ->whereKey($runId)
            ->update([
                'status' => $failed ? 'partial' : 'succeeded',
                'finished_at' => now(),
            ]);
    }
}

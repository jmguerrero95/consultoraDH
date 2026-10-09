<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\OperationalTask;
use Illuminate\Support\Facades\DB;

/**
 * Runs a decision against the **authoritative** task row.
 *
 * ## The defect this closes
 *
 * `CompleteTask`, `CancelTask` and `ReassignTask` each received a model bound by the
 * router and decided from whatever it said. Two people acting on the same task at the same
 * moment could both read `pending` and both write:
 *
 * ```
 * copy A reads  -> pending
 * copy B reads  -> pending
 * A completes   -> done
 * B cancels     -> cancelled   <-- the completion erased
 * ```
 *
 * The final state looks like an ordinary decision and the audit trail holds two
 * contradictory actions on the same task. §30 promised a row lock and the actions did not
 * take one.
 *
 * Every mutation goes through here so a new action cannot reintroduce the race by
 * forgetting, and the audit write happens inside the same transaction as the state change.
 */
final class LockedTask
{
    /**
     * @template TReturn
     *
     * @param  callable(OperationalTask): TReturn  $callback
     * @return TReturn
     */
    public static function of(int $taskId, callable $callback): mixed
    {
        return DB::transaction(
            static fn (): mixed => $callback(
                OperationalTask::query()->whereKey($taskId)->lockForUpdate()->firstOrFail(),
            ),
        );
    }
}

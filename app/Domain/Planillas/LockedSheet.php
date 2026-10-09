<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

use App\Models\ContributionSheet;
use Illuminate\Support\Facades\DB;

/**
 * Runs a lifecycle decision against the **authoritative** sheet row.
 *
 * ## The defect this closes
 *
 * The lifecycle actions received a `ContributionSheet` bound by the router. Route model
 * binding reads the row, and between that read and the write another request can move the
 * sheet. The scenario is short and real:
 *
 * ```
 * copy A reads  -> submitted
 * copy B reads  -> submitted
 * A marks paid   -> paid
 * B cancels     -> cancelled   <-- a paid month rewritten
 * ```
 *
 * `paid` is history. A cancellation decided from a stale `submitted` model breaks that,
 * and nothing downstream could notice: the row would simply say `cancelled`.
 *
 * ## Why the re-read happens here
 *
 * The fix is not "the action should be careful". It is that the decision and the write
 * are made against a row nobody else can move between them: `FOR UPDATE` inside one
 * transaction, so a second transaction either waits and then sees the new status, or is
 * refused. Every state-changing action goes through `of()` so a new action cannot
 * accidentally reintroduce the race by forgetting.
 *
 * The state machine itself is unchanged.
 */
final class LockedSheet
{
    /**
     * @template TReturn
     *
     * @param  callable(ContributionSheet): TReturn  $callback
     * @return TReturn
     */
    public static function of(int $sheetId, callable $callback): mixed
    {
        return DB::transaction(
            static fn (): mixed => $callback(
                ContributionSheet::query()->whereKey($sheetId)->lockForUpdate()->firstOrFail(),
            ),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A background task finished successfully.
 *
 * ## Why this is an event and not a call inside the worker
 *
 * Because "tell the person" and "do the work" are not the same obligation, and
 * coupling them means every future notification type has to be remembered by
 * whoever next edits the worker. The worker announces a fact; whoever cares
 * decides what to do about it.
 *
 * It is dispatched after the transaction that records success, and only on
 * success: a failed task is something the person learns about by looking, not
 * by being interrupted about it while they are doing something else.
 */
final class AiTaskCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $taskId,
    ) {}
}

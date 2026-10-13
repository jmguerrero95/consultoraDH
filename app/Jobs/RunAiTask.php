<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Ai\AiToolRejected;
use App\Events\AiTaskCompleted;
use App\Models\AiTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs one background task, and is the only thing that moves its status.
 *
 * ## Why one job for every kind of work
 *
 * Retries, backoff, the claim check, the success and failure transitions and the
 * notification hook all live here. If each kind of work had its own job, each
 * would have its own copy of those, and the copy that forgot the claim check
 * would be the one that ran the same document twice.
 *
 * ## Not doing the work twice
 *
 * The claim is a conditional update, not a read followed by a write: two workers
 * that receive the same job at the same moment both try
 * `where status = queued`, and exactly one row is affected. The loser returns
 * without touching anything. This is the difference between "we usually do not
 * double-process" and "double-processing is not possible", and Redis will
 * absolutely deliver a job twice when a worker is killed mid-flight.
 *
 * ## Bounded, not endless
 *
 * `$tries` and `backoff` cap the work. After the last attempt the task is marked
 * failed with a reason a person can read, rather than being retried until the
 * queue gives up - which on a poisoned input is an infinite loop that costs
 * money and fixes nothing.
 */
class RunAiTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * Attempts before the task is recorded as failed.
     *
     * Three, not one: parsing a large document can lose a race with a worker
     * restart, and that is worth a second try. A file that is genuinely
     * unparseable fails the same way three times, which is cheap and gives a
     * clearer signal than a single unlucky attempt.
     */
    /**
     * The queue this work runs on.
     *
     * Named rather than left on `default`, so document and research work can be
     * scaled, paused and drained on their own. Running a long parse on the same
     * queue as everything else means one big document delays a password reset.
     */
    public string $queue = 'ai-tasks';

    public int $tries = 3;

    /**
     * Seconds between attempts.
     *
     * Spaced out because the usual reason to retry is contention, and retrying
     * immediately into the same contention is how one bad document becomes a
     * queue of bad documents.
     */
    public array $backoff = [10, 60];

    /**
     * The deployment's retry policy, applied to this job.
     *
     * Read in the constructor rather than assigned afterwards, because
     * `dispatch()` is static: it builds a *new* instance from its arguments, so
     * anything set on an instance before dispatching it is silently discarded
     * and the job runs on the class defaults instead. That is invisible in
     * production - the defaults are also three - and wrong in a test that
     * shortened the policy, which then quietly tested the real one.
     */
    /**
     * How long a `running` row may sit untouched before another worker may take
     * it over.
     *
     * Comfortably longer than any document parse, so a slow-but-alive worker is
     * never robbed of its job, and short enough that a person is not looking at
     * a permanently "running" task after a restart.
     */
    private const STALE_CLAIM_SECONDS = 900;

    public function __construct(
        public readonly int $taskId,
    ) {
        $this->tries = (int) config('ai.tasks.tries', 3);
        $this->backoff = (array) config('ai.tasks.backoff', [10, 60]);
    }

    public function handle(AiTaskHandlerResolver $handlers): void
    {
        $task = AiTask::query()->find($this->taskId);

        if ($task === null) {
            // The person deleted it, or it was cleaned up. Nothing to do, and
            // nothing to report to anybody.
            return;
        }

        // A cancel that landed while this job sat in the queue, or a job that
        // already finished. Redis will redeliver a job whose worker died after
        // the work was saved but before the acknowledgement, and re-running it
        // would overwrite a good result with a second attempt's - or send the
        // completion notification twice.
        if ($task->isFinished()) {
            return;
        }

        $handler = $handlers->for($task->kind);

        if ($handler === null) {
            $this->fail($task, 'No hay nadie que pueda hacer este tipo de trabajo.');

            return;
        }

        // The claim. Conditional, so two workers cannot both proceed.
        //
        // `queued`, or a `running` row whose worker died without ever getting to
        // release or fail it. A worker killed mid-flight - a deploy, an OOM, the
        // container being restarted - leaves the row exactly as it was, and
        // nothing else would ever move it: the row would say "running" for ever
        // and the person would watch a job that stopped three weeks ago.
        $claimed = AiTask::query()
            ->whereKey($task->id)
            ->where(function ($query): void {
                $query->where('status', AiTask::STATUS_QUEUED)
                    ->orWhere(function ($stale): void {
                        $stale->where('status', AiTask::STATUS_RUNNING)
                            ->where('started_at', '<', now()->subSeconds(self::STALE_CLAIM_SECONDS));
                    });
            })
            ->update([
                'status' => AiTask::STATUS_RUNNING,
                'started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'stage' => 'Iniciando',
                'progress' => 5,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            // Somebody else got there first, or a previous attempt is still
            // running. Either way this copy has nothing to do.
            return;
        }

        $task->refresh();

        try {
            $result = $handler->handle($task);

            $task->forceFill([
                'status' => AiTask::STATUS_SUCCEEDED,
                'progress' => 100,
                'stage' => 'Listo',
                'result' => $result,
                'error' => null,
                'finished_at' => now(),
            ])->save();

            // Announced after the row is written, and only on success.
            //
            // Wrapped because announcing is not part of doing the work. Without
            // this, a push provider that is down turns every completed document
            // into a failed job: the work is already done and saved, the listener
            // throws, and the worker retries a job whose result is already on the
            // disk. The person would see a finished document reported as failed,
            // and the work would be redone.
            //
            // The listener already handles its own send failures; this catches the
            // failures that happen before it gets that far.
            try {
                AiTaskCompleted::dispatch($task->id);
            } catch (\Throwable $e) {
                Log::warning('Task completed but could not be announced.', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            // Recorded, and the claim released, before rethrowing.
            //
            // Releasing is the part that was missing. The row was left as
            // `running`, and the claim only ever accepts `queued`, so every retry
            // found a task that was already running, skipped it, and returned
            // without doing anything: three attempts, no work, and a row stuck
            // on "running" for ever. A transient failure therefore never
            // actually retried - which is the opposite of what a bounded retry
            // is for.
            $task->forceFill([
                'status' => AiTask::STATUS_QUEUED,
                'error' => $this->readableReason($e),
                'stage' => 'Reintentando',
            ])->save();

            throw $e;
        }
    }

    /**
     * The last attempt is gone. Record something a person can act on.
     */
    public function failed(?\Throwable $exception): void
    {
        $task = $this->taskId !== null ? AiTask::query()->find($this->taskId) : null;

        if ($task === null || $task->isFinished()) {
            return;
        }

        $this->fail($task, $this->readableReason($exception));
    }

    private function fail(AiTask $task, string $reason): void
    {
        $task->forceFill([
            'status' => AiTask::STATUS_FAILED,
            'stage' => 'Falló',
            'error' => $reason,
            'finished_at' => now(),
        ])->save();

        Log::warning('AI task failed.', [
            'task_id' => $task->id,
            'kind' => $task->kind,
            'reason' => $reason,
        ]);
    }

    /**
     * A reason a person can act on, rather than a class name.
     *
     * The exception's own message is used when it is one of ours - those are
     * written in Spanish for exactly this - and everything else is described
     * generically. A stack trace or an internal class name in a progress box
     * helps nobody and leaks how the system is built.
     */
    private function readableReason(?\Throwable $e): string
    {
        if ($e === null) {
            return 'El trabajo no se pudo completar.';
        }

        if ($e instanceof AiToolRejected || $e instanceof \RuntimeException) {
            return $e->getMessage();
        }

        return 'El trabajo no se pudo completar. Inténtalo de nuevo.';
    }
}

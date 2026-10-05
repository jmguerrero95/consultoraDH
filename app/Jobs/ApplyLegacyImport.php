<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Imports\Actions\ApplyImportPlan;
use App\Domain\Imports\Actions\ImportNotApplicable;
use App\Domain\Imports\Exceptions\ImportApplyFailed;
use App\Domain\Imports\Exceptions\ImportNotFound;
use App\Domain\Imports\ImportLifecycle;
use App\Domain\Imports\ImportPlan;
use App\Domain\Imports\ImportPlanIdentity;
use App\Domain\Imports\LegacyImport;
use App\Domain\Imports\LegacyImportStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * §16: apply a plan.
 *
 * ## The job is a thin shell and that is the point
 *
 * §15 has `POST /imports/{import}/apply` and §16 has `ApplyLegacyImport`. Both must end up doing
 * the same guarded write, and the guarantee §12.2 gives — one transition, a second request gets
 * 409 — only holds if there is one code path. So this job loads the persisted actions and hands
 * them to the same `ApplyLegacyImport` action the controller calls; the lock, the state check
 * and the transaction all live there and neither caller can skip them.
 *
 * ## Not retryable
 *
 * `tries = 1`. An apply that fails has already rolled back everything, and re-running it would
 * mean applying a plan that was written against a database state nobody has looked at since.
 * §16's retry-safety is about the job being *safe to run again*, which this satisfies by
 * refusing to: the state check in `ApplyLegacyImport` turns a second run into a 409 rather than
 * a second write.
 */
final class ApplyLegacyImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $importId,
        /**
         * The revision and digest this job was authorised to apply, when it was queued by
         * something other than the endpoint.
         *
         * The endpoint applies synchronously and does not queue this job; a console-driven or
         * retry path does, and §5.4's guarantee has to hold there too. NULL means "whatever the
         * plan is now", which is only correct for a job re-running *its own* batch.
         */
        public readonly ?int $planRevision = null,
        public readonly ?string $planDigest = null,
    ) {
        $this->onConnection($this->queueConnection());
        $this->onQueue((string) config('imports.queue.queue', 'imports'));
        $this->timeout = (int) config('imports.queue.timeout', 600);
    }

    /**
     * §12.3: an apply that fails has already rolled back everything.
     *
     * Re-running it would mean applying a plan written against a database state nobody has
     * looked at since. §16's retry-safety is satisfied by refusing to: the state check turns a
     * second run into a 409 rather than a second write.
     */

    /**
     * §16's queue, resolved once.
     *
     * `null` in `config/imports.php` means "the application's default", so a deployment that sets
     * `IMPORT_QUEUE_CONNECTION=redis` gets a dedicated queue and a suite that sets
     * `QUEUE_CONNECTION=sync` runs the job inline — without either having to be patched.
     */
    private function queueConnection(): ?string
    {
        $configured = config('imports.queue.connection');

        return is_string($configured) && $configured !== ''
            ? $configured
            : (string) config('queue.default', 'sync');
    }

    public function tries(): int
    {
        return 1;
    }

    public function handle(ApplyImportPlan $applier): void
    {
        $import = LegacyImport::query()->with(['actions', 'issues'])->find($this->importId);

        if ($import === null || $import->status !== LegacyImportStatus::Ready) {
            return;
        }

        $plan = new ImportPlan(
            $import,
            $import->actions()->orderBy('ordinal')->get()->all(),
            $import->issues()->where('blocking', true)->whereNull('resolved_at')->count(),
        );

        $applier->handle(
            $import,
            $plan,
            $this->planRevision === null
                ? null
                : ImportPlanIdentity::of($import, $this->planRevision, $this->planDigest),
        );
    }

    /**
     * A refusal is not a crash.
     *
     * Somebody pressed Apply twice, or resolved something in between. The batch is in a known
     * state either way, so the job ends quietly instead of retrying into a 409 forever.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof ImportNotApplicable || $exception instanceof ImportNotFound) {
            return;
        }

        try {
            ImportLifecycle::mutate(
                $this->importId,
                fn (ImportLifecycle $lifecycle) => $lifecycle->markFailed(
                    $exception instanceof ImportApplyFailed ? 'apply_failed' : 'apply_error',
                    'La aplicación del plan falló y se revirtió por completo. '
                    .'Ningún dato quedó escrito a medias. Detalle: '.class_basename($exception),
                ),
            );
        } catch (Throwable) {
            report($exception);
        }
    }
}

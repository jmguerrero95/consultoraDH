<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Imports\Actions\ApplyImportPlan;
use App\Domain\Imports\Actions\ImportNotApplicable;
use App\Domain\Imports\ImportPlan;
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

    public int $tries = 1;

    public function __construct(public readonly int $importId) {}

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

        $applier->handle($import, $plan);
    }

    /**
     * A refusal is not a crash.
     *
     * Somebody pressed Apply twice, or resolved something in between. The batch is in a known
     * state either way, so the job ends quietly instead of retrying into a 409 forever.
     */
    public function failed(Throwable $exception): void
    {
        if ($exception instanceof ImportNotApplicable) {
            return;
        }

        LegacyImport::query()->whereKey($this->importId)->update([
            'status' => LegacyImportStatus::Failed->value,
            'failed_at' => now(),
            'failure_code' => 'apply_failed',
            'failure_message' => 'La aplicación del plan falló y se revirtió por completo. '
                .'Ningún dato quedó escrito a medias. Detalle: '.class_basename($exception),
        ]);
    }
}

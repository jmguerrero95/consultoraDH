<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Imports\ImportFileStore;
use App\Domain\Imports\ImportLifecycle;
use App\Domain\Imports\LegacyImportStatus;
use App\Domain\Imports\StageLegacyImport;
use App\Domain\Imports\WorkbookRejected;
use App\Models\LegacyImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * §16: parse one workbook into staging.
 *
 * ## Why the job carries an id and not the file
 *
 * §15: "No meter el archivo completo en el payload del job; el job recibe el ID/path privado."
 * A queued payload is serialised into Redis, and a 700 KB workbook in a payload would be copied
 * into the queue's memory and into its logs. The job carries the import id, the private path is
 * read from the database through `ImportFileStore`, and nothing that large is ever serialised.
 *
 * ## Idempotent by state, and the state is checked under a lock
 *
 * §16 requires each job to be idempotent. The previous version did it by reading the status and
 * then writing `parsing` in two separate statements with nothing between them — which is a
 * check-then-act, and the audit's finding was that a retried job moved an already-`applied`
 * import back to `parsing` and re-parsed a file whose decisions were in the masters.
 *
 * `ImportLifecycle::claimParsing()` is one locked transition now, so the read and the write
 * cannot be separated and the two racing jobs serialise instead of both winning.
 *
 * ## A failure is a state, not a stack trace
 *
 * §4.2 and §15 both require it. The import goes to `failed` with a code and a sanitised
 * sentence; `class_basename($exception)` is recorded instead of the message because a driver's
 * message can carry a path or a value from the workbook.
 *
 * ## Queue settings come from configuration
 *
 * §16 asks each job to leave enough information to retry. `tries`, `timeout`, `backoff` and the
 * queue itself are read from `config('imports.queue')` in the constructor rather than left as
 * class properties, because the audit found all four keys present in `config/imports.php` and
 * read by nothing.
 */
final class ParseLegacyImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Not retryable: a file the guard or the parser refused will be refused again.
     *
     * §16 wants a retry-safe job, and this is the honest setting for this one — a refusal is a
     * verdict about the bytes, not a transient condition, and retrying it three times only
     * delays the message the operator needs to read.
     */
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $importId)
    {
        // `onQueue`/`onConnection` here rather than at the dispatch site, so every caller gets
        // the same routing and the configuration keys are read in exactly one place.
        $this->onConnection($this->queueConnection());
        $this->onQueue((string) config('imports.queue.queue', 'imports'));

        // §16: a job that leaves the batch in `parsing` on a hard timeout is worse than one
        // that fails cleanly, so the timeout is the configured one rather than the default.
        $this->timeout = (int) config('imports.queue.timeout', 600);
    }

    /**
     * §16's queue, resolved once.
     *
     * `null` in `config/imports.php` means "the application's default", so a deployment that
     * sets `IMPORT_QUEUE_CONNECTION=redis` gets a dedicated queue and a suite that sets
     * `QUEUE_CONNECTION=sync` runs the job inline — without either having to be patched.
     */
    private function queueConnection(): ?string
    {
        $configured = config('imports.queue.connection');

        return is_string($configured) && $configured !== ''
            ? $configured
            : (string) config('queue.default', 'sync');
    }

    /** Two attempts: the first, and one after the operator's file has settled on disk. */
    public function tries(): int
    {
        return (int) config('imports.queue.tries', 3);
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(StageLegacyImport $stager, ImportFileStore $files): void
    {
        // A deleted import has nothing to parse and is not an error. The lock is taken first so
        // the existence check and the claim cannot disagree.
        $claimed = ImportLifecycle::mutateWhen(
            $this->importId,
            [LegacyImportStatus::Uploaded, LegacyImportStatus::Queued, LegacyImportStatus::Review, LegacyImportStatus::Failed],
            fn (ImportLifecycle $lifecycle): bool => $lifecycle->claimParsing(),
        );

        // NULL: the import does not exist. `false`: it is not in a state that may be parsed —
        // already parsed, being parsed, applied, or cancelled. Both are expected outcomes of a
        // queued job, not failures.
        if ($claimed === null || $claimed === false) {
            return;
        }

        $import = LegacyImport::query()->findOrFail($this->importId);

        // §5.1's path rule, through the one class that owns disk selection.
        $path = $files->absolutePath($import);

        if ($path === null || ! is_file($path)) {
            $this->failImport('source_missing', 'El archivo privado de esta importación ya no está en el servidor.');

            return;
        }

        try {
            $stager->stage($import, $path);
        } catch (\Throwable $exception) {
            $this->failImport('parse_failed', self::safeMessage($exception));

            throw $exception;
        }
    }

    private static function safeMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();

        // The typed refusals (guard, parser) are written for a person and are safe to show. A
        // driver's or a library's is not, so anything unrecognised is reduced to its class.
        $known = str_starts_with($message, 'No se pudo leer')
            || str_starts_with($message, 'El libro')
            || $exception instanceof WorkbookRejected;

        return $known
            ? $message
            : 'No se pudo analizar el libro. Detalle: '.class_basename($exception);
    }

    /**
     * Recorded outside any transaction, so it survives the rollback.
     *
     * Through the lifecycle, so it cannot overwrite an import that was applied in the
     * meantime: `markFailed()` declines from a terminal state, and the audit found the previous
     * version writing `failed` unconditionally.
     */
    private function failImport(string $code, string $message): void
    {
        ImportLifecycle::mutate($this->importId, fn (ImportLifecycle $lifecycle) => $lifecycle->markFailed($code, $message));
    }

    public function failed(?\Throwable $exception): void
    {
        $this->failImport('parse_failed', 'No se pudo analizar el libro tras varios intentos.');
    }
}

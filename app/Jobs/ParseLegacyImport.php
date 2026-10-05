<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Imports\LegacyImportStatus;
use App\Domain\Imports\StageLegacyImport;
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
 * read from the database, and nothing that large is ever serialised.
 *
 * ## Idempotent by state
 *
 * §16 requires each job to be idempotent. Re-running a parse replaces the staged rows and
 * issues rather than appending, so a retry converges on the same result instead of doubling
 * the staging table. An import that is already applied or cancelled is refused outright: there
 * is nothing to gain by re-reading a file whose decisions are already in the masters.
 *
 * ## A failure is a state, not a stack trace
 *
 * §4.2 and §15 both require it. The import goes to `failed` with a code and a sanitised
 * sentence; `class_basename($exception)` is recorded instead of the message because a driver's
 * message can carry a path or a value from the workbook.
 */
final class ParseLegacyImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 10;

    /** Not retryable: a file the guard or the parser refused will be refused again. */
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $importId) {}

    public function handle(StageLegacyImport $stager): void
    {
        $import = LegacyImport::query()->find($this->importId);

        if ($import === null) {
            // The import was deleted while the job waited. Nothing to do, and not an error.
            return;
        }

        if (in_array($import->status, [LegacyImportStatus::Applied, LegacyImportStatus::Cancelled], true)) {
            return;
        }

        $import->forceFill(['status' => LegacyImportStatus::Parsing->value])->save();

        $path = $import->absolutePath();

        if ($path === null || ! is_file($path)) {
            $this->failImport($import, 'source_missing', 'El archivo privado de esta importación ya no está en el servidor.');

            return;
        }

        try {
            $stager->stage($import, $path);
        } catch (\Throwable $exception) {
            $this->failImport($import, 'parse_failed', $this->safeMessage($exception));

            throw $exception;
        }
    }

    private function safeMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();

        // The typed refusals (guard, parser) are written for a person and are safe to show. A
        // driver's or a library's is not, so anything unrecognised is reduced to its class.
        $known = str_starts_with($message, 'No se pudo leer') || str_starts_with($message, 'El libro');

        return $known
            ? $message
            : 'No se pudo analizar el libro. Detalle: '.class_basename($exception);
    }

    /** Recorded outside any transaction, so it survives the rollback. */
    private function failImport(LegacyImport $import, string $code, string $message): void
    {
        LegacyImport::query()->whereKey($import->id)->update([
            'status' => LegacyImportStatus::Failed->value,
            'failed_at' => now(),
            'failure_code' => $code,
            'failure_message' => $message,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $import = LegacyImport::query()->find($this->importId);

        if ($import === null || $import->status === LegacyImportStatus::Failed) {
            return;
        }

        $this->failImport($import, 'parse_failed', 'No se pudo analizar el libro tras varios intentos.');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Imports\Exceptions;

use RuntimeException;

/**
 * §15's "the import does not exist", as a 404 rather than a 500.
 *
 * Worth its own class because the callers differ in what they do next. A job that references a
 * deleted import has nothing to do and must not report an error — the audit's job handlers all
 * began with `if ($import === null) return;`, which is correct and deserves to be explicit
 * rather than incidental. An endpoint has to answer 404.
 *
 * The message names the id, which is safe: ids are sequential integers of the operator's own
 * uploads, and this exception never carries a filename, a path or a cell.
 */
final class ImportNotFound extends RuntimeException
{
    public function __construct(public readonly int $importId)
    {
        parent::__construct("No existe una importación con el identificador {$importId}.");
    }

    /** §15's 404 body. */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => 'import_not_found',
            'import_id' => $this->importId,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Imports\Actions;

use App\Domain\Imports\LegacyImportStatus;
use App\Models\LegacyImport;

/**
 * Apply was refused, and the caller has to turn that into a 409 with a code. §15.
 *
 * ## Four reasons, four different sentences
 *
 * "409" alone is useless to an operator whose double-click raced a background job. What
 * differs is whether they should retry, wait, or go and resolve something — so each reason
 * carries its own code and its own next step.
 *
 * ## `alreadyApplied` also covers §12.1
 *
 * The same class refuses a *second* application of a file whose hash was already applied,
 * whether it is the same import row or a different upload of the same workbook. §12.1 asks for
 * a link to the previous import, and that is what `previousImportId` carries.
 */
final class ImportNotApplicable extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?int $previousImportId = null,
    ) {
        parent::__construct($message);
    }

    /** §12.2: a second request for an import that is already applying or applied. */
    public static function alreadyApplied(LegacyImport $import, ?int $previousImportId = null): self
    {
        return new self(
            $previousImportId === null
                ? 'Esta importación ya se aplicó. No se puede aplicar dos veces.'
                : 'Este archivo ya fue aplicado en otra importación. No se puede volver a aplicar.',
            'already_applied',
            $previousImportId,
        );
    }

    /** The import is not in the one state from which Apply is allowed. */
    public static function wrongState(LegacyImport $import, LegacyImportStatus $required): self
    {
        return new self(
            sprintf(
                'Esta importación está en «%s» y sólo se puede aplicar desde «%s».',
                $import->status?->label() ?? $import->status,
                $required->label(),
            ),
            'wrong_state',
        );
    }

    /** §17.5: Apply stays disabled while a blocker is unresolved. */
    public static function unresolvedBlockers(LegacyImport $import, int $count): self
    {
        return new self(
            sprintf(
                'Faltan %d incidencias bloqueantes por resolver. No se puede aplicar.',
                $count,
            ),
            'unresolved_blockers',
        );
    }

    /** The plan has nothing to write — every action is a no-op. */
    public static function nothingToApply(LegacyImport $import): self
    {
        return new self(
            'El plan no tiene acciones pendientes: todo lo que traía el archivo ya existe igual.',
            'nothing_to_apply',
        );
    }

    public function userMessage(): string
    {
        return $this->getMessage();
    }
}

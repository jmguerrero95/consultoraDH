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

    /**
     * The import is not in a state this operation may run from.
     *
     * ## Why the message is not apply-specific any more
     *
     * This class is now the single place a state transition can be refused — `ImportLifecycle`
     * throws it for every transition, not only for Apply — so a sentence that said "sólo se
     * puede aplicar desde…" produced this, from a *staging* call, on a batch in `queued`:
     *
     * > "Esta importación está en «En cola» y sólo se puede aplicar desde «En revisión»."
     *
     * which is three different mistakes in one line: apply was never mentioned, `review` was
     * named as the source state when the enum's `queued` row does not reach it, and an operator
     * reading it had no way to tell what to do.
     *
     * Both states are named plainly, and the caller that knows which operation it is can add its
     * own sentence — which is what `reason` is for.
     */
    public static function wrongState(LegacyImport $import, LegacyImportStatus $required, ?string $operation = null): self
    {
        return new self(
            sprintf(
                'Esta importación está en «%s»%s y sólo puede pasar a «%s».',
                $import->status?->label() ?? (string) $import->status,
                $operation === null ? '' : ' ('.$operation.')',
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

    /** A person cancelled the batch; nothing may move it again. */
    public static function cancelled(LegacyImport $import): self
    {
        return new self(
            'Esta importación fue cancelada. No se puede aplicar.',
            'cancelled',
        );
    }

    /**
     * §5.4: the plan that would run is not the plan that was reviewed.
     *
     * ## Why this is a distinct refusal and not `wrong_state`
     *
     * The batch is perfectly applicable — it is `ready`, it has no blockers and it has a plan.
     * What is wrong is that the plan *moved* between the reviewer reading it and pressing
     * Apply: another tab resolved an issue, or the plan job finished late.
     *
     * The audit found `apply` accepted no request body at all, so this could not be detected:
     * the reviewer approved one set of changes and a different set was written, with nothing
     * logged. §5.4 forbids exactly that — "no reconstruir una explicación distinta a la que
     * realmente aplicará el backend".
     *
     * @param  array{code: string, revision: int, digest: string}  $detail  from
     *                                                                      `ImportPlanIdentity::explainMismatch()`, which distinguishes "the content changed"
     *                                                                      from "it was rebuilt to the same content" — different next steps for the operator.
     */
    public static function stalePlan(LegacyImport $import, array $detail): self
    {
        return new self(
            match ($detail['code']) {
                'plan_not_confirmed' => 'No se confirmó qué revisión del plan se iba a aplicar. Recargue y revise el plan antes de aplicar.',
                'plan_rebuilt' => sprintf(
                    'El plan se reconstruyó (revisión %d) sin cambiar su contenido. Recargue para confirmar la revisión actual.',
                    $detail['revision'],
                ),
                default => sprintf(
                    'El plan cambió después de que lo revisara: ahora es la revisión %d y la que se revisó ya no es la que se aplicaría. '
                    .'Recargue y revise el plan de nuevo.',
                    $detail['revision'],
                ),
            },
            $detail['code'],
        );
    }

    public function userMessage(): string
    {
        return $this->getMessage();
    }
}

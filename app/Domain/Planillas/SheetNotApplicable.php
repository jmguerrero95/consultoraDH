<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

use RuntimeException;

/**
 * A planilla operation that cannot be carried out.
 *
 * §63: a domain conflict is never a 500. Every refusal here carries a machine-readable `reason` so
 * the interface can branch on it, and the controller maps the class to 409/422/404.
 */
final class SheetNotApplicable extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /** §20: the preview no longer describes the source it was computed from. */
    public static function stalePreview(string $expected, string $actual): self
    {
        return new self(
            'preview_stale',
            'La vista previa ya no corresponde a la información actual. Vuelva a previsualizar.',
            ['expected_digest' => $expected, 'current_digest' => $actual],
        );
    }

    /** §17: the move is not in the lifecycle. */
    public static function wrongState(ContributionSheetStatus $from, ContributionSheetStatus $to): self
    {
        return new self(
            'wrong_state',
            sprintf(
                'Una planilla en «%s» no puede pasar a «%s».',
                $from->label(),
                $to->label(),
            ),
            ['from' => $from->value, 'to' => $to->value],
        );
    }

    /** §17: a paid sheet is history. */
    public static function paidIsHistory(): self
    {
        return new self(
            'paid_is_history',
            'Una planilla pagada es histórico y no vuelve a borrador. Registre la corrección en un período posterior.',
        );
    }

    /** §16: `other` needs a name. */
    public static function operatorNotNamed(): self
    {
        return new self(
            'operator_not_named',
            'Indique el nombre del operador cuando la opción sea «Otro».',
        );
    }

    /** §24: submitted needs a reference and a date. */
    public static function missingSubmissionData(): self
    {
        return new self(
            'missing_submission_data',
            'Indique el número de planilla o la referencia, y la fecha de envío.',
        );
    }

    /** §24: paid needs proof. */
    public static function missingPaymentProof(): self
    {
        return new self(
            'missing_payment_proof',
            'Adjunte al menos un comprobante de pago antes de marcar la planilla como pagada.',
        );
    }

    /** §24/§17: cancelling needs a reason. */
    public static function missingCancellationReason(): self
    {
        return new self(
            'missing_cancellation_reason',
            'Explique por qué se cancela la planilla.',
        );
    }

    /** A line that is being edited does not belong to this sheet. */
    public static function lineNotFound(): self
    {
        return new self('line_not_found', 'La línea no pertenece a esta planilla.');
    }

    /** §23: excluding a generated candidate requires a reason. */
    public static function missingExclusionReason(): self
    {
        return new self('missing_exclusion_reason', 'Explique por qué se excluye esta persona.');
    }

    /** §9.3: money is an integer, and a negative amount is not money. */
    public static function negativeAmount(): self
    {
        return new self('negative_amount', 'El valor liquidado no puede ser negativo.');
    }

    /** §16: `other` needs a name, on the sheet as well as on creation. */
    public static function operatorNotNamedForSheet(): self
    {
        return new self('operator_not_named', 'Indique el nombre del operador cuando la opción sea «Otro».');
    }

    /** §23: only a draft may be edited. */
    public static function notEditable(ContributionSheetStatus $status): self
    {
        return new self(
            'not_editable',
            sprintf('Sólo una planilla en borrador puede editarse. Esta está en «%s».', $status->label()),
        );
    }
}

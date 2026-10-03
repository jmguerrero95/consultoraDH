<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * An adjustment was refused.
 *
 * Every one of these is a case where accepting the request would put the ledger into
 * a state nobody could explain afterwards: a zero row, a reversal of a reversal, an
 * obligation that has been paid more than it is worth.
 *
 * The two overpayment cases are the important ones. An obligation that has been paid
 * 200000 and is then reduced to 150000 has no representation in this system: there is
 * no negative balance to record a refund against, and inventing one would hide a
 * reconciliation problem. The correct order is to correct the payment first.
 */
final class AdjustmentRejected extends \RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function reasonRequired(): self
    {
        return new self(
            'Debe indicar el motivo del ajuste. Queda registrado y no puede modificarse después.',
            'reason_required',
        );
    }

    public static function zeroDelta(): self
    {
        return new self(
            'El ajuste debe ser distinto de cero: un importe de cero no cambia nada y '
            .'solo añadiría una fila al libro que luego habría que interpretar.',
            'zero_delta',
        );
    }

    public static function reversalIsNotAnOrdinaryAdjustment(): self
    {
        return new self(
            'Una reversión se crea con la acción de deshacer, no como un ajuste nuevo.',
            'reversal_not_ordinary',
        );
    }

    public static function wouldGoBelowZero(int $deltaCop): self
    {
        return new self(
            'El ajuste dejaría la obligación con un importe efectivo negativo, '
            .'lo que no tiene representación en este sistema. Corrija primero los pagos.',
            'would_go_below_zero',
        );
    }

    public static function wouldOverpay(int $paidAmount, int $effectiveAfter): self
    {
        return new self(sprintf(
            'El ajuste dejaría la obligación con un importe efectivo de %d pesos, '
            .'menor que los %d pesos ya aplicados. Eso produciría un sobrepago, '
            .'que el sistema no puede representar: corrija primero la aplicación del pago.',
            $effectiveAfter,
            $paidAmount,
        ), 'would_overpay');
    }

    public static function alreadyReversed(): self
    {
        return new self(
            'Este ajuste ya fue revertido. No se puede revertir dos veces.',
            'already_reversed',
        );
    }

    public static function cannotReverseAReversal(): self
    {
        return new self(
            'Una reversión no se puede revertir. Revierta el ajuste original.',
            'cannot_reverse_reversal',
        );
    }

    public static function reversalWouldBreachTheBalance(int $paidAmount, int $effectiveAfter): self
    {
        return new self(sprintf(
            'Revertir este ajuste dejaría la obligación en %d pesos efectivos con %d ya aplicados. '
            .'Corrija primero la aplicación de los pagos.',
            $effectiveAfter,
            $paidAmount,
        ), 'reversal_would_breach');
    }

    public static function notFound(): self
    {
        return new self('El ajuste ya no existe.', 'not_found');
    }

    public static function obligationNotFound(): self
    {
        return new self('La obligación ya no existe.', 'obligation_not_found');
    }
}

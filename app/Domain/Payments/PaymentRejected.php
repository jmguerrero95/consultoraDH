<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * A payment operation was refused.
 *
 * Each of these stops a state the ledger cannot represent honestly: money applied
 * twice, an obligation paid more than it is worth, a voided payment used, a
 * reversal applied twice.
 */
final class PaymentRejected extends \RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function amountMustBePositive(int $amount): self
    {
        return new self(
            sprintf('El importe del pago debe ser mayor que cero; se recibió %d.', $amount),
            'amount_not_positive',
        );
    }

    public static function amountMustBeAWholeNumber(): self
    {
        return new self(
            'El importe del pago debe ser un número entero de pesos. '
            .'No se admiten decimales: este sistema maneja pesos enteros.',
            'amount_not_whole',
        );
    }

    public static function receivedDateRequired(): self
    {
        return new self('Debe indicar la fecha en que se recibió el pago.', 'received_on_required');
    }

    public static function receivedDateInvalid(string $value): self
    {
        return new self(
            sprintf('La fecha de recepción «%s» no es válida.', $value),
            'received_on_invalid',
        );
    }

    public static function methodRequired(): self
    {
        return new self('Debe indicar el método de pago.', 'method_required');
    }

    public static function methodInvalid(string $value): self
    {
        return new self(
            sprintf('El método de pago «%s» no es válido.', $value),
            'method_invalid',
        );
    }

    public static function allocationMustBePositive(int $amount): self
    {
        return new self(
            sprintf('La aplicación debe ser mayor que cero; se recibió %d.', $amount),
            'allocation_not_positive',
        );
    }

    public static function exceedsPaymentBalance(int $requested, int $available): self
    {
        return new self(sprintf(
            'El pago solo tiene %d pesos sin aplicar y se intentó aplicar %d.',
            $available,
            $requested,
        ), 'exceeds_payment_balance');
    }

    public static function exceedsObligationBalance(int $requested, int $remaining): self
    {
        return new self(sprintf(
            'La obligación solo tiene %d pesos pendientes y se intentó aplicar %d.',
            $remaining,
            $requested,
        ), 'exceeds_obligation_balance');
    }

    public static function differentClient(int $obligationClientId, int $paymentClientId): self
    {
        return new self(sprintf(
            'Este pago pertenece al cliente %d y la obligación al cliente %d. '
            .'Un pago solo puede aplicarse a obligaciones del mismo cliente.',
            $paymentClientId,
            $obligationClientId,
        ), 'different_client');
    }

    public static function paymentIsVoided(): self
    {
        return new self(
            'El pago está anulado, así que no admite aplicaciones.',
            'payment_voided',
        );
    }

    public static function alreadyVoided(): self
    {
        return new self('El pago ya está anulado.', 'already_voided');
    }

    public static function alreadyReversed(): self
    {
        return new self('Esta aplicación ya fue revertida.', 'already_reversed');
    }

    public static function duplicateAllocation(): self
    {
        return new self(
            'Ya existe una aplicación activa de este pago a esta obligación.',
            'duplicate_allocation',
        );
    }

    public static function reasonRequired(): self
    {
        return new self(
            'Debe indicar el motivo. Queda registrado y no puede modificarse después.',
            'reason_required',
        );
    }

    public static function paymentNotFound(): self
    {
        return new self('El pago ya no existe.', 'payment_not_found');
    }

    public static function allocationNotFound(): self
    {
        return new self('La aplicación ya no existe.', 'allocation_not_found');
    }

    public static function clientNotFound(): self
    {
        return new self('El cliente ya no existe.', 'client_not_found');
    }
}

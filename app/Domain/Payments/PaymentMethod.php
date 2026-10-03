<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * How money arrived.
 *
 * Four values, kept small on purpose. There is no gateway, no card brand and no
 * provider here: A03 records money that has already arrived, and a payment gateway
 * is not something this module does.
 *
 * `other` exists so that an unfamiliar method is recorded honestly rather than
 * forced into the nearest of the other three. A mislabelled method is worse than a
 * vague one.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Deposit = 'deposit';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::BankTransfer => 'Transferencia bancaria',
            self::Deposit => 'Consignación',
            self::Other => 'Otro',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Periods;

use App\Models\MonthlyPeriod;

/**
 * A structural change was attempted on a closed period.
 *
 * Closed freezes **structure**: obligations, their base amounts, their due dates and
 * the membership that produced them. It does not freeze money, and this exception is
 * never thrown for a payment.
 *
 * The message says what closing means for this month, because the most common
 * reaction to a refusal is to assume the period is unusable. It is not: money still
 * moves against it.
 */
final class PeriodIsClosed extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forStructuralChange(MonthlyPeriod $period): self
    {
        return new self(sprintf(
            'El periodo %s está cerrado: no se pueden generar ni modificar obligaciones. '
            .'Los pagos, las aplicaciones y los ajustes sí siguen permitidos. '
            .'Si necesita corregir la generación, reabra el periodo con su motivo.',
            $period->label(),
        ));
    }
}

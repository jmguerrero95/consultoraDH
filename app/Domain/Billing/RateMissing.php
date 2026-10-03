<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * No configured monthly amount for this client and company in this month.
 *
 * A blocker, not a warning, and not something to fill in with a default. The amount
 * a client owes is the central economic fact of this module; producing one that
 * nobody configured would put an invented number into a real invoice.
 */
final class RateMissing extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function for(int $clientId, int $companyId, string $periodKey): self
    {
        return new self(sprintf(
            'No hay valor configurado para el cliente %d en la empresa %d con vigencia %s.',
            $clientId,
            $companyId,
            $periodKey,
        ));
    }
}

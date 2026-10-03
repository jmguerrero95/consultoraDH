<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\ClientCompanyRate;
use App\Models\MonthlyObligation;

/**
 * A rate an obligation already quotes cannot be edited.
 *
 * The obligation stores the rate's identifier as the evidence for its amount. Editing
 * that rate afterwards would change what the system says it billed for a month that
 * has already been generated, which is precisely the silent recalculation this
 * module refuses: "what were we billed in March?" would change its answer in November.
 *
 * The way to change a value is a new row with a later `effective_month`. The history
 * then reads as a sequence of decisions, which is what it is.
 *
 * A row nobody has used may be corrected, because a mistake in configuration that
 * never reached an obligation has not changed anybody's bill.
 */
final class RateInUse extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function for(ClientCompanyRate $rate): self
    {
        return new self(sprintf(
            'El valor configurado con vigencia %s ya se usó para generar obligaciones, '
            .'y modificarlo cambiaría lo que el sistema dice que facturó. '
            .'Cree un valor nuevo con un mes de vigencia posterior.',
            $rate->month()->label(),
        ));
    }

    public static function assertEditable(ClientCompanyRate $rate, mixed $actor = null): void
    {
        $used = MonthlyObligation::query()
            ->where('rate_id', $rate->id)
            ->exists();

        if ($used) {
            throw self::for($rate);
        }
    }
}

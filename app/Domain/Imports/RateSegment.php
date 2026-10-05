<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Periods\MonthlyPeriod;

/**
 * One run of months at one monthly value, for one client at one company. §10.
 *
 * ## §10 calls this part deterministic, and it is the easiest thing in A04
 *
 * "mismo valor consecutivo → no repetir rate; cuando cambia → nueva `ClientCompanyRate` con
 * `effective_month` = primer día del mes de la hoja". There is no inference here at all, which
 * is why the class is so plain.
 *
 * ## The start month is the *sheet's* month, not the affiliation date
 *
 * This is the one that is easy to get wrong and §10 says it twice: "primera observación válida
 * → rate desde ese mes, **no desde FECHA AFILIACION**". The workbook asserts a salary for a
 * month; it does not assert a salary for every month since the person was hired. Using the
 * affiliation date would invent four months of a rate nobody stated, and A03 would then
 * generate obligations against an amount the file never contained.
 *
 * ## Whole pesos, and why the amount is a string
 *
 * A03 has one rule for every amount in the system: whole pesos, positive, no decimals. The
 * value is carried as a normalised decimal *string* so the plan can print exactly what it will
 * write and the fingerprint stays stable regardless of how PHP renders a float.
 */
final readonly class RateSegment implements \JsonSerializable
{
    /**
     * @param  list<string>  $months  the sheet months covered, ascending
     * @param  list<int>  $sourceRowIds
     */
    public function __construct(
        public string $clientKey,
        public ?string $documentType,
        public ?string $documentNumber,
        public ?string $companyTaxId,
        public MonthlyPeriod $effectiveMonth,
        public string $amount,
        public array $months,
        public array $sourceRowIds,
    ) {}

    public function amountAsInt(): int
    {
        return (int) $this->amount;
    }

    public function naturalKey(): string
    {
        return 'rate:'.($this->documentNumber ?? 'no-document')
            .':'.($this->companyTaxId ?? 'no-nit')
            .':'.$this->effectiveMonth->key();
    }

    public function lastMonth(): ?string
    {
        return $this->months === [] ? null : $this->months[count($this->months) - 1];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client' => $this->clientKey,
            'company_tax_id' => $this->companyTaxId,
            'effective_month' => $this->effectiveMonth->key(),
            'amount' => $this->amount,
            'months' => $this->months,
            'source_row_ids' => $this->sourceRowIds,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * One episode of a person working for a company, rebuilt from the monthly snapshots.
 *
 * ## §8.1's grouping key, and why the date is in it
 *
 * `client + empresa + fecha_inicio_normalizada`. The date is the part that is not obvious: the
 * workbook states a month for almost every row, so without the date every month would look like
 * a re-hire and one employment would become ten relationships. With it, "the same person at the
 * same employer with the same start date, again next month" is one episode observed twice —
 * which is what §8.1 says the repeated months mean.
 *
 * ## The end comes from evidence, never from absence
 *
 * An episode that stops appearing is *not* closed. Absence of evidence is not evidence of
 * ending: the person may have moved employers, and closing the relationship would delete a
 * month of history that A03's obligations may still depend on. §8.4 makes a disappearance a
 * blocker instead, and that decision is recorded on the reconstruction rather than invented
 * here. Only a retirement note closes an episode.
 */
final readonly class RelationshipEpisode implements \JsonSerializable
{
    /**
     * @param  list<string>  $months  the sheet months this episode was seen in, ascending
     * @param  list<int>  $sourceRowIds  the staged rows that produced it, for §13
     */
    public function __construct(
        public string $clientKey,
        public ?string $documentType,
        public ?string $documentNumber,
        public ?string $companyTaxId,
        public ?string $companyName,
        public HistoricalInterval $interval,
        public array $months,
        public array $sourceRowIds,
        public ?RetirementNote $retirement,
        public ?string $clientDisplayName = null,
    ) {}

    /** §8.1's key, as a string: the three things that make an episode an episode. */
    public function key(): string
    {
        return implode('|', [
            $this->clientKey,
            $this->companyTaxId ?? 'no-nit',
            $this->interval->start?->format('Y-m-d') ?? 'unknown',
        ]);
    }

    /** The key an action row uses, so a plan is reproducible from its own natural keys. */
    public function naturalKey(): string
    {
        return 'relationship:'.($this->companyTaxId ?? 'no-nit')
            .':'.($this->documentNumber ?? 'no-document')
            .':'.($this->interval->start?->format('Y-m-d') ?? 'unknown');
    }

    public function firstMonth(): ?string
    {
        return $this->months[0] ?? null;
    }

    public function lastMonth(): ?string
    {
        return $this->months === [] ? null : $this->months[count($this->months) - 1];
    }

    /** Whether the episode was ever closed by evidence. */
    public function hasClosureEvidence(): bool
    {
        return $this->retirement !== null || ! $this->interval->isOpen();
    }

    public function withInterval(HistoricalInterval $interval): self
    {
        return new self(
            $this->clientKey,
            $this->documentType,
            $this->documentNumber,
            $this->companyTaxId,
            $this->companyName,
            $interval,
            $this->months,
            $this->sourceRowIds,
            $this->retirement,
            $this->clientDisplayName,
        );
    }

    /**
     * A one-line description with §8.3's precision wording.
     *
     * Used in the messages a reviewer reads, so an approximate boundary reads as approximate
     * there too rather than only in the detail panel.
     */
    public function describe(): string
    {
        $company = $this->companyName ?? ($this->companyTaxId ?? 'empresa sin NIT');
        $months = $this->firstMonth() === null ? '' : ' ('.$this->firstMonth().'–'.$this->lastMonth().')';

        return sprintf(
            '%s desde %s hasta %s%s',
            $company,
            $this->interval->describeStart(),
            $this->interval->describeEnd(),
            $months,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'client' => $this->clientKey,
            'company_tax_id' => $this->companyTaxId,
            'company_name' => $this->companyName,
            'interval' => $this->interval->toArray(),
            'months' => $this->months,
            'source_row_ids' => $this->sourceRowIds,
            'has_retirement' => $this->retirement !== null,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

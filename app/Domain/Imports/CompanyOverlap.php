<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * §8.5: one person's episodes at two companies whose rebuilt intervals overlap.
 *
 * ## Co-occurrence is not a parallel
 *
 * §8.5 is blunt about this: in the real file the same client appears at several companies in
 * the same month *en masse* during retirements and transfers. A rule that blocked on
 * co-occurrence would bury the real findings under thousands of false ones, so the test is
 * overlap of the rebuilt intervals, and the months in which both were actually observed.
 *
 * ## The three answers, and why none is chosen here
 *
 * §8.5 lists what the resolution may do: correct a date, recognise a transfer, or authorise a
 * parallel with an explicit reason using A02's columns. This class records which of those is
 * plausible and attaches the evidence, and it has no field for "approved" — an approval is a
 * resolution on the import's issue row, where §15's endpoint can find it and §13 can audit who
 * decided what.
 */
final readonly class CompanyOverlap implements \JsonSerializable
{
    /**
     * @param  list<string>  $sharedMonths  months both episodes were observed in
     */
    public function __construct(
        public string $clientKey,
        public RelationshipEpisode $first,
        public RelationshipEpisode $second,
        public array $sharedMonths,
    ) {}

    /**
     * Whether the two were seen together, or whether one of them simply never closed.
     *
     * The difference matters to whoever resolves it: two employers in the same month is a
     * different question from one open relationship that predates the next one, and the first
     * is usually a transfer recorded a month late.
     */
    public function isSimultaneous(): bool
    {
        return $this->sharedMonths !== [];
    }

    public function message(): string
    {
        $companies = array_filter([
            $this->first->companyName ?? $this->first->companyTaxId,
            $this->second->companyName ?? $this->second->companyTaxId,
        ]);

        return sprintf(
            'Las relaciones con %s se solapan entre %s y %s. Hay que corregir una fecha, reconocer un traslado o autorizar un paralelo.',
            implode(' y ', $companies),
            $this->first->describe(),
            $this->second->describe(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client' => $this->clientKey,
            'first' => $this->first->naturalKey(),
            'second' => $this->second->naturalKey(),
            'first_interval' => $this->first->interval->toArray(),
            'second_interval' => $this->second->interval->toArray(),
            'shared_months' => $this->sharedMonths,
            'simultaneous' => $this->isSimultaneous(),
            'source_row_ids' => array_values(array_unique(array_merge(
                $this->first->sourceRowIds,
                $this->second->sourceRowIds,
            ))),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

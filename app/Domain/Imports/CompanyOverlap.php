<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * §8.5: one person's episodes at two companies whose rebuilt intervals overlap.
 *
 * ## Co-occurrence is not a parallel, and neither is an unbounded span
 *
 * §8.5 is blunt about co-occurrence: in the real file the same client appears at several
 * companies in the same month *en masse* during retirements and transfers, so a shared month
 * on its own is not a finding.
 *
 * The specification's actual test is the rebuilt intervals — "después de reconstruir intervalos" —
 * and `HistoryReconstructor::genuineOverlap()` is where that is decided. What reaches this class
 * has therefore already been shown to overlap *affirmatively*: both ends known and
 * intersecting, or a dated start falling inside a closed interval, or (as the narrowest case)
 * two unbounded episodes co-observed in an overlapping month.
 *
 * `sharedMonths` is carried as *evidence for the reviewer*, not as the reason the finding
 * exists. §8.5 warns that this shape is usually a transfer recorded a month late, which is why
 * `isSimultaneous()` reads as a hint about which of §8.5's three resolutions is plausible rather
 * than as a classification.
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
     * Whether the two were seen in the same month.
     *
     * §8.5's three resolutions are "corregir fecha", "reconocer transferencia", "autorizar
     * paralelo con motivo explícito", and this is which of them the evidence leans towards. When
     * both employers appear in one sheet, the likeliest answer is a transfer written a month
     * late; when the overlap comes only from dated boundaries, it is likelier to be a wrong date
     * or a genuine parallel. It is a hint for the dialog, never a decision.
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

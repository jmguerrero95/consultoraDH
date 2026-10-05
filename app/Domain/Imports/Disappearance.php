<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * §8.4: an episode that stops appearing before the last month of the file, with no evidence.
 *
 * ## What a person has to answer
 *
 * One of two things is true, and the file cannot say which: the relationship ended and nobody
 * wrote why, or the person moved to an employer this workbook does not cover. §8.4 makes it a
 * blocker because closing it on absence alone would delete history, and leaving it open
 * silently is the other half of the same wrong answer.
 *
 * The class carries the months so the review screen can show "seen Jan–Jun, the file runs to
 * Dec" — the evidence a person needs to decide — and the staged rows so the decision can be
 * recorded against something specific.
 */
final readonly class Disappearance implements \JsonSerializable
{
    /**
     * @param  list<int>  $sourceRowIds
     */
    public function __construct(
        public string $episodeKey,
        public string $clientKey,
        public ?string $companyTaxId,
        public ?string $companyName,
        public ?string $firstMonth,
        public ?string $lastSeenMonth,
        /** The last month the workbook covers, so the gap is visible. */
        public ?string $fileLastMonth,
        public array $sourceRowIds,
    ) {}

    /** How many months pass between the last sighting and the end of the file. */
    public function missingMonths(): int
    {
        if ($this->lastSeenMonth === null || $this->fileLastMonth === null) {
            return 0;
        }

        // Months between the last sighting and the end of the file, exclusive at both ends:
        // the person was seen in `lastSeen`, and the file simply stops describing them.
        //
        // Computed as `year * 12 + month` rather than by comparing the `Y-m` strings or their
        // digits: `2026-12` to `2027-01` is one month, and both shortcuts call it eighty-nine.
        $seen = explode('-', $this->lastSeenMonth);
        $end = explode('-', $this->fileLastMonth);

        $gap = ((int) $end[0] * 12 + (int) $end[1])
            - ((int) $seen[0] * 12 + (int) $seen[1]);

        return max(0, $gap - 1);
    }

    public function message(): string
    {
        return sprintf(
            'La relación con %s aparece hasta %s y el archivo llega hasta %s, sin retiro ni otra evidencia de cierre.',
            $this->companyName ?? ('NIT '.$this->companyTaxId),
            $this->lastSeenMonth ?? '?',
            $this->fileLastMonth ?? '?',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'episode' => $this->episodeKey,
            'company_tax_id' => $this->companyTaxId,
            'first_month' => $this->firstMonth,
            'last_seen_month' => $this->lastSeenMonth,
            'file_last_month' => $this->fileLastMonth,
            'missing_months' => $this->missingMonths(),
            'source_row_ids' => $this->sourceRowIds,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

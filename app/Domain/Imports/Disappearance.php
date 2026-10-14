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
        /**
         * The retirement note the row carried, when it carried one.
         *
         * ## Why this is here
         *
         * An episode can stop appearing before the end of the file *and* carry a retirement note.
         * Under `manual_only` that note yields no boundary — §8.2 forbids deriving one — so the
         * relationship stays open and the reviewer still has to say when it ended.
         *
         * Before this was carried, the review screen told such a reviewer "sin retiro ni otra
         * evidencia de cierre" ("no retirement or other evidence of closure") about a row that
         * plainly had a retirement written in it. A question phrased with a false premise gets
         * answered without reading it, which is worse than not asking.
         */
        public ?RetirementNote $retirement = null,
        public ?ImportRetirementPolicy $policy = null,
    ) {}

    /** Whether the source recorded a retirement this episode could not be dated from. */
    public function hasUndatedRetirement(): bool
    {
        return $this->retirement !== null;
    }

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
        $where = sprintf(
            'La relación con %s aparece hasta %s y el archivo llega hasta %s',
            $this->companyName ?? ('NIT '.$this->companyTaxId),
            $this->lastSeenMonth ?? '?',
            $this->fileLastMonth ?? '?',
        );

        // Two different questions, and they must not be phrased the same way. See the
        // constructor's note on `$retirement`.
        if ($this->hasUndatedRetirement()) {
            return $where
                .'. El archivo anota un retiro, pero ninguna regla lo convierte en una fecha, '
                .'así que hace falta decidir en qué día termina la relación.';
        }

        return $where.', sin retiro ni otra evidencia de cierre.';
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
            'undated_retirement' => $this->hasUndatedRetirement(),
            'retirement_summary' => $this->retirement === null || $this->policy === null
                ? null
                : $this->retirement->summary($this->policy),
            'source_row_ids' => $this->sourceRowIds,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

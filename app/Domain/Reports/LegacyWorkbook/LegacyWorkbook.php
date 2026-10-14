<?php

declare(strict_types=1);

namespace App\Domain\Reports\LegacyWorkbook;

/**
 * A whole workbook: one sheet per selected month, each holding its company blocks.
 */
final readonly class LegacyWorkbook
{
    /**
     * @param  array<string, list<LegacyCompanyBlock>>  $sheets  `YYYY-MM` => blocks
     * @param  array<string, string>  $sheetNames  `YYYY-MM` => `ENERO 2026`
     * @param  array<string, bool>  $emptyMonths  `YYYY-MM` => whether the month had no rows at all
     */
    public function __construct(
        public array $sheets,
        public array $sheetNames,
        public array $emptyMonths,
        public LegacyWorkbookSelection $selection,
        public string $generatedAt,
    ) {}

    public function sheetCount(): int
    {
        return count($this->sheets);
    }

    public function blockCount(): int
    {
        $total = 0;

        foreach ($this->sheets as $blocks) {
            $total += count($blocks);
        }

        return $total;
    }

    public function rowCount(): int
    {
        $total = 0;

        foreach ($this->sheets as $blocks) {
            foreach ($blocks as $block) {
                $total += count($block->rows);
            }
        }

        return $total;
    }
}

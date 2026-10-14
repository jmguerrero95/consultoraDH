<?php

declare(strict_types=1);

namespace App\Domain\Reports\LegacyWorkbook;

/**
 * One company's block inside one month's sheet.
 *
 * A block, not a flat list, because the historical workbook is organised that way: a company
 * title, a header row, its workers, and then the next company. The `#` sequence column restarts at
 * 1 for every block, exactly as it does in the original, because that is what a person reading it
 * expects and what re-importing it relies on.
 */
final readonly class LegacyCompanyBlock
{
    /**
     * @param  list<LegacyWorkerRow>  $rows
     */
    public function __construct(
        public int $companyId,
        public string $companyTitle,
        public array $rows,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Services\Ai;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Stops a spreadsheet reader at a row and column limit.
 *
 * A workbook declares its used range, and a small file can declare a range that
 * is far larger than anything a report can use. The limit has to be applied
 * while the reader is building cells, not after: by then the memory has already
 * been spent. This is the input-side counterpart of the guards in
 * {@see Xlsx\XlsxWorkbookBuilder}.
 */
final class TabularReadFilter implements IReadFilter
{
    public function __construct(
        private readonly int $rowLimit,
        private readonly int $columnLimit,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        if ($row > $this->rowLimit) {
            return false;
        }

        try {
            return Coordinate::columnIndexFromString($columnAddress) <= $this->columnLimit;
        } catch (\Throwable) {
            // An address the library cannot parse is not a cell this reader is
            // willing to materialise.
            return false;
        }
    }
}

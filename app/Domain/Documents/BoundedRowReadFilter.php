<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Stops a spreadsheet reader at a row limit.
 *
 * ## Why this exists as a named class
 *
 * A workbook declares a used range. A file of a few kilobytes can declare a
 * billion rows, and a reader that honours the declaration will happily try to
 * build a billion `Cell` objects — turning a small upload into a request that
 * never returns and never reports. The limit has to be applied while reading,
 * not after, which is the only thing a read filter does.
 *
 * It is a named class rather than an anonymous one because the reader
 * type-hints against the interface by name, and an anonymous class passed
 * inline is not resolvable that way at the point the reader inspects it.
 */
final class BoundedRowReadFilter implements IReadFilter
{
    public function __construct(
        private readonly int $rowLimit,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row <= $this->rowLimit;
    }
}

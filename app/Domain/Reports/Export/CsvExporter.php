<?php

declare(strict_types=1);

namespace App\Domain\Reports\Export;

/**
 * Renders a `ReportResult` as CSV, streamed rather than buffered.
 *
 * §53: "Do not write massive exports fully into PHP memory." The callback form lets the
 * rows be written as they are produced, so a report with a hundred thousand rows costs a
 * row's memory rather than the whole file's.
 *
 * UTF-8 is declared in a BOM so Excel on Windows opens accented Colombian names correctly
 * instead of rendering them as mojibake.
 */
final class CsvExporter
{
    /** @return callable(): void A generator body suitable for a streamed response. */
    public function stream(iterable $rows, array $columns): callable
    {
        return function () use ($rows, $columns): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, array_map(
                static fn (array $column): string => $column['label'],
                $columns,
            ), ';', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    static fn (array $column): string => (string) FormulaGuard::cell($row[$column['key']] ?? ''),
                    $columns,
                ), ';', '"', '\\');
            }

            fclose($handle);
        };
    }
}

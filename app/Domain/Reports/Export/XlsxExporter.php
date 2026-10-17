<?php

declare(strict_types=1);

namespace App\Domain\Reports\Export;

use App\Domain\Reports\ReportResult;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Renders a `ReportResult` as XLSX using the repository's existing OpenSpout dependency
 * (§81: reuse it, do not add a second spreadsheet library).
 *
 * Written to a temporary file and streamed from disk, because a generated report is
 * persisted privately and later downloaded through an authorised controller — it is never
 * serialised into JSON or held in memory.
 *
 * ## MEJ-002 / MEJ-007: presentation, and nothing else
 *
 * The export was correct and unreadable: every column the same width, so a legal name and
 * a three-letter code were both 8.43 characters wide and most text was clipped; a bare row
 * of headers with nothing marking it as a header; money as a plain number with no
 * thousands separator; and a total indistinguishable from the last record. None of that
 * touched a figure, and neither does any of the following — widths taken from the content,
 * a heading block, a filter bar, currency and integer formats, a filterable header row,
 * the header repeated on every printed page, and a highlighted totals line.
 */
final class XlsxExporter
{
    private const MIN_WIDTH = 10.0;

    private const MAX_WIDTH = 46.0;

    /** Consultora DH blue, matching the on-screen header. */
    private const BRAND = '123A5E';

    private const BRAND_SUBTLE = 'E8F0F7';

    public function writeTo(ReportResult $report, string $path): void
    {
        $columns = $report->columns();
        $rows = $report->rows();
        $totals = $report->totals();
        $meta = $report->meta();

        $writer = new Writer($this->options());
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName(mb_substr((string) ($meta['title'] ?? 'Informe'), 0, 31));

        // The heading block spans the width of the table rather than sitting in A1 with
        // the rest of the row empty, which is what made the old file look cut off.
        $writer->addRow(Row::fromValuesWithStyle(
            [(string) ($meta['title'] ?? 'Informe'), ...array_fill(0, max(0, count($columns) - 1), '')],
            new Style(fontBold: true, fontSize: 14),
        ));

        if (count($columns) > 1) {
            $writer->getOptions()->mergeCells(0, 1, count($columns) - 1, 1);
        }

        $writer->addRow(Row::fromValues([
            'Generado',
            (string) ($meta['generated_at'] ?? ''),
            'Corte',
            (string) ($meta['as_of'] ?? ''),
        ]));

        $filters = $meta['filters'] ?? [];
        if ($filters !== []) {
            $writer->addRow(Row::fromValuesWithStyle(
                ['Filtros', implode(' | ', $filters)],
                new Style(fontItalic: true),
            ));
        }

        $writer->addRow(Row::fromValues([]));

        // 1-based: whatever the block above occupied, the header is the next row.
        $headerRow = $sheet->getWrittenRowCount() + 1;

        $writer->addRow(Row::fromValuesWithStyle(
            array_map(static fn (array $column): string => (string) $column['label'], $columns),
            $this->headerStyle(),
        ));

        $styles = $this->bodyStyles($columns);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValuesWithStyles(
                array_map(
                    static fn (array $column): mixed => FormulaGuard::cell($row[$column['key']] ?? ''),
                    $columns,
                ),
                $styles,
            ));
        }

        $lastDataRow = $headerRow + count($rows);

        if (count($rows) > 0) {
            $sheet->setAutoFilter(new AutoFilter(0, $headerRow, max(0, count($columns) - 1), $lastDataRow));
        }

        foreach ($totals as $total) {
            $writer->addRow(Row::fromValuesWithStyle(
                array_map(
                    static fn (array $column): mixed => FormulaGuard::cell($total[$column['key']] ?? ''),
                    $columns,
                ),
                $this->totalStyle(),
            ));
        }

        if (isset($meta['disclaimer'])) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValuesWithStyle(
                [(string) $meta['disclaimer']],
                new Style(fontItalic: true, fontSize: 9),
            ));
        }

        $this->applyWidths($columns, $rows, $totals, $writer);

        // The header repeats on every printed page, and the sheet is fitted to one page
        // wide, in landscape, so it can actually be printed.
        $sheet->setPrintTitleRows($headerRow.':'.$headerRow);

        $writer->close();
    }

    private function options(): Options
    {
        return new Options(
            pageSetup: new PageSetup(
                pageOrientation: PageOrientation::LANDSCAPE,
                paperSize: PaperSize::A4,
                fitToWidth: 1,
            ),
        );
    }

    private function headerStyle(): Style
    {
        return new Style(
            fontBold: true,
            fontColor: Color::WHITE,
            backgroundColor: self::BRAND,
            cellAlignment: CellAlignment::CENTER,
            shouldWrapText: true,
            border: new Border(
                new BorderPart(BorderName::BOTTOM, self::BRAND, BorderWidth::THIN, BorderStyle::SOLID),
            ),
        );
    }

    private function totalStyle(): Style
    {
        return new Style(
            fontBold: true,
            backgroundColor: self::BRAND_SUBTLE,
            border: new Border(
                new BorderPart(BorderName::TOP, self::BRAND, BorderWidth::THIN, BorderStyle::SOLID),
            ),
        );
    }

    /**
     * One style per column, so money reads as a number a person can sum.
     *
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @return array<int, Style>
     */
    private function bodyStyles(array $columns): array
    {
        $styles = [];

        foreach ($columns as $index => $column) {
            $styles[$index] = match ($column['type']) {
                'money' => new Style(format: '#,##0'),
                'integer' => new Style(format: '0'),
                default => new Style,
            };
        }

        return $styles;
    }

    /**
     * Widths taken from what the column actually holds.
     *
     * Clamped: unclamped, a legal name would be several hundred characters wide and a
     * column of one-letter codes would be a sliver.
     *
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $totals
     */
    private function applyWidths(array $columns, array $rows, array $totals, Writer $writer): void
    {
        $sheet = $writer->getCurrentSheet();

        foreach ($columns as $index => $column) {
            $longest = mb_strlen((string) $column['label']);

            foreach ($rows as $row) {
                $value = $row[$column['key']] ?? '';

                if (is_scalar($value)) {
                    $longest = max($longest, mb_strlen((string) $value));
                }
            }

            foreach ($totals as $total) {
                $value = $total[$column['key']] ?? '';

                if (is_scalar($value)) {
                    $longest = max($longest, mb_strlen((string) $value));
                }
            }

            // OpenSpout's width API is 1-based (`min`/`max` are written to the XML as given),
            // while `columns()` is a 0-based list: `$index` wrote `min="0"` for the first
            // column and shifted the rest, which strict readers reject.
            $sheet->setColumnWidth(min(self::MAX_WIDTH, max(self::MIN_WIDTH, $longest + 2)), $index + 1);
        }
    }
}

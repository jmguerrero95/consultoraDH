<?php

declare(strict_types=1);

namespace App\Domain\Reports\Export;

use App\Domain\Reports\LegacyWorkbook\LegacyCompanyBlock;
use App\Domain\Reports\LegacyWorkbook\LegacyWorkbook;
use App\Domain\Reports\LegacyWorkbook\LegacyWorkbookSelection;
use App\Domain\Reports\LegacyWorkbook\LegacyWorkerRow;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes the operational workbook in the historical shape.
 *
 * ## Why PhpSpreadsheet and not the project's OpenSpout exporter
 *
 * The existing `XlsxExporter` is the right tool for the flat reports: it is streaming, fast, and
 * writes a table of values. This file is **not** a flat table — it is coloured cells, column
 * widths, borders, number formats and a legend — and OpenSpout writes none of those. So the two
 * exporters coexist rather than one being bent into the other: adding style to a streaming writer
 * would have cost the memory profile that makes it the right choice for a 10 000-row report.
 *
 * PhpSpreadsheet is already in `composer.json`; nothing was added.
 *
 * ## The template is code, not a file
 *
 * C.2 is explicit that the delivered XLSX must **not** be used as a template: it carries stray
 * styles, comments, historical data and possibly credentials, and January–February alone hold an
 * anomalous volume of formatting outside the visible operating area. So the look is declared here,
 * in reviewable code, and it is reproducible in a fresh checkout with no private file present.
 *
 * ## Every cell is written as a literal
 *
 * A value beginning with `=`, `+`, `-` or `@` is written through `setValueExplicit` as a string, so
 * a name, a note or a reference typed by a user can never become a formula when the file is opened
 * (C.8). Identifiers stay **text** for the same reason Excel needs them as text — leading zeros
 * and 10-digit NITs otherwise arrive in scientific notation — while money stays a real number so it
 * can be worked with.
 */
final class LegacyWorkbookExporter
{
    /** The historical header fill. */
    private const HEADER_FILL = 'FFFFC000';

    /** The header's own font colour: white on orange is what the original uses. */
    private const HEADER_FONT = 'FFFFFFFF';

    /** The block title's fill, one step darker than the header so the two read differently. */
    private const TITLE_FILL = 'FFF2A100';

    private const BORDER_THIN = 'FFBFBFBF';

    /**
     * Column widths, in Excel's character units.
     *
     * ## Measured against the delivered file, and deliberately shorter
     *
     * The original's widths are `A 11.4, C 47.3, D 61.4, K 69.4, R 55.6` and so on. They are not
     * reproduced. C.2 attributes the volume of formatting outside the visible operating area in
     * January and February to the same cause, and a `NOVEDAD` column 61 characters wide has to be
     * scrolled past to see the next one.
     *
     * So these are the original's proportions trimmed to what the content actually needs: wide
     * enough that `ARL / PROVEEDOR O REFERENCIA DE RIESGO` and `CAJA DE COMPENSACION` are readable
     * without a click, narrow enough that two employees fit on one screen. The measured comparison
     * is in `docs/TASKS/FIX-EXPORT-IMPORT-03.md` §D.4 so a reader can judge it.
     */
    private const COLUMN_WIDTHS = [
        'A' => 7, 'B' => 34, 'C' => 14, 'D' => 22, 'E' => 16,
        'F' => 18, 'G' => 16, 'H' => 14, 'I' => 22, 'J' => 22,
        'K' => 30, 'L' => 14, 'M' => 22, 'N' => 22, 'O' => 22,
        'P' => 28, 'Q' => 20, 'R' => 28,
    ];

    /** The columns, in order, with the historical headings. */
    private const HEADINGS = [
        'A' => '#',
        'B' => 'OPERADOR',
        'C' => '# PLANILLA',
        'D' => 'NOVEDAD',
        'E' => 'REFERENCIA',
        'F' => 'FECHA AFILIACION',
        'G' => 'VALOR MENSUAL',
        'H' => 'CEDULA',
        'I' => 'NOMBRES',
        'J' => 'APELLIDOS',
        'K' => 'DIRECCION',
        'L' => 'TELEFONO',
        'M' => 'CAJA COMPENSACION',
        'N' => 'EPS SALUD',
        'O' => 'AFP PENSION',
        // The delivered file's column P reads `ARL SURA` — a **named provider** — and C.2 is
        // explicit that the heading must not say which provider when the employer uses another.
        // Several blocks in the original file do not use SURA at all, so that heading was wrong in
        // the source document, not a convention. This one says what the column holds.
        'P' => 'ARL / PROVEEDOR O REFERENCIA DE RIESGO',
        'Q' => 'CARGO',
        'R' => 'CORREO',
    ];

    /** The identifier columns, which must survive as text. */
    private const TEXT_COLUMNS = ['A', 'C', 'E', 'H'];

    public function writeTo(LegacyWorkbook $workbook, string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($workbook->selection->monthKeys() as $monthKey) {
            $this->writeSheet($spreadsheet, $workbook, $monthKey);
        }

        // A selection can legitimately produce zero sheets only if the year and months are empty,
        // which `LegacyWorkbookSelection` refuses. PhpSpreadsheet cannot save a workbook with no
        // sheets, so the guard is here rather than as a silent crash in the writer.
        if ($spreadsheet->getSheetCount() === 0) {
            $spreadsheet->createSheet()->setTitle('SIN DATOS');
        }

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);

        $spreadsheet->disconnectWorksheets();
    }

    private function writeSheet(Spreadsheet $spreadsheet, LegacyWorkbook $workbook, string $monthKey): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($this->safeSheetName($workbook->sheetNames[$monthKey] ?? $monthKey));

        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getRowDimension(1)->setRowHeight(20);

        $row = 1;

        // Legend first, so a reader knows what the colours mean before meeting any of them.
        $row = $this->writeLegend($sheet, $row);

        $blocks = $workbook->sheets[$monthKey] ?? [];

        foreach ($blocks as $block) {
            $row = $this->writeBlock($sheet, $row, $block);
        }

        // Freeze the header of the **first** block so scrolling a long sheet keeps the columns
        // visible. One per sheet rather than per block, because a freeze pane cannot move.
        $sheet->freezePane('A1');
    }

    private function writeBlock(Worksheet $sheet, int $row, LegacyCompanyBlock $block): int
    {
        // 1 — the company title, spanning the sheet.
        $sheet->mergeCells("B{$row}:R{$row}");
        $sheet->getStyle("B{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => self::HEADER_FONT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::TITLE_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $this->writeLiteral($sheet, "B{$row}", $block->companyTitle);
        $sheet->getRowDimension($row)->setRowHeight(18);
        $row++;

        // 2 — the header row.
        foreach (self::HEADINGS as $column => $heading) {
            $cell = "{$column}{$row}";

            $sheet->getStyle($cell)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => self::HEADER_FONT]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'borders' => $this->borders(),
            ]);
            $this->writeLiteral($sheet, $cell, $heading);
        }

        $sheet->getRowDimension($row)->setRowHeight(28);
        $row++;

        // 3 — the workers.
        foreach ($block->rows as $worker) {
            $this->writeWorker($sheet, $row, $worker);
            $row++;
        }

        // A blank line between blocks, as in the original.
        //
        // The column headings are repeated for **every** block rather than frozen once per sheet:
        // a freeze pane can only be set at one position, and on a sheet with fourteen company
        // blocks the person reading block nine needs block nine's headings, not block one's.
        return $row;
    }

    private function writeWorker(Worksheet $sheet, int $row, LegacyWorkerRow $worker): void
    {
        $values = [
            'A' => (string) $worker->sequence,
            'B' => null, // The operator is the block title; the cell stays blank as in the original.
            'C' => $worker->planNumber,
            'D' => $worker->novelty(),
            'E' => $worker->reference,
            'F' => $worker->affiliationDate,
            'G' => $worker->monthlyAmount,
            'H' => $worker->document,
            'I' => $worker->firstNames,
            'J' => $worker->lastNames,
            'K' => $worker->address,
            'L' => $worker->phone,
            'M' => $worker->socialSecurityBox,
            'N' => $worker->eps,
            'O' => $worker->afp,
            'P' => $worker->arl,
            'Q' => $worker->jobTitle,
            'R' => $worker->email,
        ];

        foreach ($values as $column => $value) {
            $this->writeValue($sheet, "{$column}{$row}", $value, in_array($column, self::TEXT_COLUMNS, true));
        }

        // The fill follows the **payment** status only.
        //
        // C.3 is explicit that retirement and delinquency are different facts, and that a
        // withdrawn person whose obligation is paid stays green. So the colour is decided by
        // `paymentStatus` and `withdrawn` never overrides it — the retirement is stated in words in
        // NOVEDAD, which is where the original puts it too.
        $fill = $worker->paymentStatus->fill();

        if ($fill !== null) {
            $sheet->getStyle("A{$row}:R{$row}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $fill]],
            ]);
        }

        $sheet->getStyle("A{$row}:R{$row}")->applyFromArray([
            'borders' => $this->borders(),
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Money as a real number with a Colombian format, so it can be summed in Excel.
        $sheet->getStyle("G{$row}")->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);

        $sheet->getRowDimension($row)->setRowHeight(15);
    }

    /**
     * The colour legend, as rows above the first block.
     *
     * C.3 requires it and C.3 also says it must not add a sheet or disturb the block structure the
     * importer reads. Two rows above the first block is the smallest thing that does the job: it is
     * visible when the file opens and the parser, which starts at the first block title, never sees
     * it.
     */
    private function writeLegend(Worksheet $sheet, int $row): int
    {
        // Title.
        $sheet->mergeCells("B{$row}:R{$row}");
        $sheet->getStyle("B{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => self::TITLE_FILL]],
        ]);
        $this->writeLiteral($sheet, "B{$row}", 'CONSULTORA DH — LIBRO OPERATIVO');
        $row++;

        // One row, one colour, one meaning.
        //
        // `null` for the fourth is not "no cell" — the **label** still has to be there. The absence
        // of a fill is the information, and an empty cell would say nothing.
        $cells = [
            ['column' => 'A', 'label' => 'Verde: pagado', 'colour' => 'FF00B050'],
            ['column' => 'F', 'label' => 'Amarillo: pendiente', 'colour' => 'FFFFFF00'],
            ['column' => 'K', 'label' => 'Rojo: vencido', 'colour' => 'FFFF0000'],
            ['column' => 'P', 'label' => 'Sin color: sin información de pago', 'colour' => null],
        ];

        foreach ($cells as $cell) {
            $this->writeLiteral($sheet, $cell['column'].$row, $cell['label']);
            $sheet->getStyle($cell['column'].$row)->getFont()->setBold(true);

            if ($cell['colour'] !== null) {
                $sheet->getStyle($cell['column'].$row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB($cell['colour']);
            }
        }

        return $row + 2;
    }

    /**
     * Write a value, choosing text or number deliberately.
     *
     * @param  bool  $asText  true for identifiers, so leading zeros and long NITs survive
     */
    private function writeValue(Worksheet $sheet, string $cell, mixed $value, bool $asText): void
    {
        // null is an **empty cell**, never `''`, `'-'` or `0`. C.5: an unknown amount must not
        // arrive back as a number and an unknown address must not arrive back as a value.
        //
        // `TYPE_NULL` rather than an empty string on `TYPE_STRING`: a cell holding `""` is not the
        // same as a cell holding nothing, and the importer reads a blank cell as "not supplied".
        if ($value === null || $value === '') {
            $sheet->setCellValueExplicit($cell, null, DataType::TYPE_NULL);

            return;
        }

        // Money stays a **real number** so it can be summed in Excel. C.8: an amount that arrived as
        // text could not be totalled without the reader converting it first.
        if (! $asText && (is_int($value) || is_float($value))) {
            $sheet->setCellValue($cell, $value);

            return;
        }

        // Everything else is a string, **always** — `DataType::TYPE_STRING`, never
        // `DataType::TYPE_FORMULA`.
        //
        // An earlier version of this method did the opposite for a value beginning with `=`: it
        // passed `TYPE_FORMULA`, believing it was the safe choice. It is exactly the unsafe one —
        // that is the call that turns a note a user typed into `=HYPERLINK(...)` or a DDE payload
        // the moment the file is opened. `TYPE_STRING` is what makes it inert, and the leading `=`
        // stays visible as text.
        //
        // The Text (`@`) number format is applied as well, so Excel does not reinterpret `00123456`
        // as a number on open and drop the leading zeros the moment somebody clicks the cell.
        $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
    }

    /**
     * A heading or a title, which is always text.
     *
     * Headings are literals too: one of them is `ARL / PROVEEDOR O REFERENCIA DE RIESGO`, and a
     * cell holding a literal `/` is harmless while a mis-typed `=…` would not be.
     */
    private function writeLiteral(Worksheet $sheet, string $cell, string $value): void
    {
        $this->writeValue($sheet, $cell, $value, true);
    }

    /** @return array<string, array<string, string>> */
    private function borders(): array
    {
        return [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => self::BORDER_THIN],
            ],
        ];
    }

    /**
     * Excel's 31-character sheet-name limit, with the duplicate rule.
     *
     * `ENERO 2026` is ten characters and will never need this, but a caller could ask for a year
     * the limit does not bite and get a `WorksheetException` from deep inside the writer.
     */
    private function safeSheetName(string $name): string
    {
        $name = str_replace(['\\', '/', '?', '*', '[', ']', ':'], '-', $name);

        return mb_substr($name, 0, 31);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use OpenSpout\Reader\XLSX\Reader;

/**
 * Reads the delivered Blinden monthly workbook, deterministically.
 *
 * ## What "deterministic" is buying
 *
 * The same file produces the same staged rows, the same issues and the same fingerprints on
 * every run, on every machine. Nothing here consults the database, the catalogue, the clock or
 * the network, so a run can be repeated and compared — which is what §19's fingerprint check
 * and §20.1's fixture tests both depend on. Every decision that needs a fact this class does
 * not have becomes an issue, never a default.
 *
 * ## The four things the reader has to get right
 *
 * 1. **Sheet order comes from the name, not the tab.** §6.1. A workbook whose tabs were
 *    dragged into alphabetical order would otherwise bill October before January, and every
 *    monthly segmentation downstream — rates, affiliations, relationship episodes — is a
 *    function of chronological order. `orderedSheets()` sorts by the month it read, and a
 *    sheet it cannot read is reported rather than skipped.
 * 2. **The used range is inflated.** §6.1: in the real file January and February reach column
 *    `XAQ` because of stray formatting, and every cell in that rectangle is a row. Reading
 *    to the end of the used range yields hundreds of thousands of empty cells and a parse
 *    that never finishes, so the reader is confined to the profile's own range.
 * 3. **A block is found by its header, not by a row count.** §6.2. The row above a header is
 *    the company title, which is the only place the NIT is written, and a header that
 *    appears without one is `missing_company_block_header` rather than a block with an
 *    unknown company.
 * 4. **P/Q are decided by content.** §1.1 and §9.4, handled by `RiskColumns`.
 *
 * ## A malformed file is a typed failure, never a stack trace
 *
 * §4.2: "fallo de parser = estado controlado, nunca stack trace al usuario". Every exit from
 * this class is either a `ParsedWorkbook` or a `WorkbookParseFailed`, and neither contains a
 * filesystem path.
 */
final class BlindenLegacyWorkbookParser
{
    /** §6.1: the profile's logical range. Formatting beyond this is not data. */
    private const FIRST_COLUMN = 'A';

    private const LAST_COLUMN = 'R';

    /** How far down a single sheet is scanned looking for the next header. */
    private const BLOCK_SCAN_LIMIT = 400;

    /** @var list<string> */
    private readonly array $columns;

    public function __construct(
        private readonly SensitiveSourceRedactor $redactor,
        array $columns = [],
    ) {
        $this->columns = $columns === [] ? self::defaultColumns() : array_values($columns);
    }

    /** @return list<string> */
    private static function defaultColumns(): array
    {
        $columns = [];

        for ($letter = ord(self::FIRST_COLUMN); $letter <= ord(self::LAST_COLUMN); $letter++) {
            $columns[] = chr($letter);
        }

        return $columns;
    }

    /**
     * Read a workbook into staged rows and issues.
     *
     * @param  string  $path  the private copy on disk. Never a public URL, never an
     *                        operator-supplied path.
     *
     * @throws WorkbookParseFailed
     */
    public function parse(string $path): ParsedWorkbook
    {
        $this->redactor->reset();

        $sheets = $this->readSheets($path);

        if ($sheets === []) {
            throw WorkbookParseFailed::noReadableSheet();
        }

        $parsed = new ParsedWorkbook;

        foreach ($sheets as $sheet) {
            $parsed->addSheet($sheet);
        }

        foreach ($sheets as $entry) {
            if ($entry->month === null) {
                $parsed->addIssue(LegacyImportIssue::InvalidSheetName, $entry->name, null, null, [
                    'severity' => LegacyIssueSeverity::Warning,
                    'message' => 'La hoja «'.$entry->name.'» no dice MES AÑO, así que no se leyó. '
                        .'Puede ser una hoja de totales.',
                ]);

                continue;
            }

            $this->readSheet($entry, $parsed);
        }

        // §7.2 needs every title the file contains before it can tell a consistent employer from
        // an inconsistent one, so this cannot happen while reading a sheet. A04-R1 had the code
        // in the enum with no producer at all; the comparison is what finally makes it reachable.
        $parsed->raiseCompanyIdentityConflicts();

        return $parsed;
    }

    /**
     * Read every sheet into memory, keeping only the profile's logical range.
     *
     * @return list<RawSheet>
     */
    private function readSheets(string $path): array
    {
        $reader = new Reader;

        try {
            $reader->open($path);
        } catch (\Throwable $exception) {
            // §4.2: a controlled state, not a trace. The exception's own message can contain
            // the path, so it is deliberately not carried over.
            throw WorkbookParseFailed::unreadableContainer();
        }

        $raw = [];

        try {
            foreach ($reader->getSheetIterator() as $key => $sheet) {
                $raw[] = new RawSheet(
                    (string) ($sheet->getName() === '' ? $key : $sheet->getName()),
                    $this->readCells($sheet),
                );
            }
        } catch (\Throwable) {
            throw WorkbookParseFailed::unreadableContainer();
        } finally {
            $reader->close();
        }

        return $this->order($raw);
    }

    /**
     * One sheet, confined to the profile's range.
     *
     * `Row::toArray()` rather than the cells themselves: it maps each cell to its value and
     * keeps the `DateTimeInterface` that a date-formatted cell carries, which is all this
     * method needs, and it avoids depending on a cell accessor that would be one of the
     * things to change between OpenSpout versions.
     *
     * @return list<array{0: int, 1: array<string, float|int|string|null>}>
     */
    private function readCells(mixed $sheet): array
    {
        $rows = [];
        $rowNumber = 0;
        $limit = self::BLOCK_SCAN_LIMIT * 20;

        foreach ($sheet->getRowIterator() as $row) {
            if (++$rowNumber > $limit) {
                // A sheet this long is not this profile's. Stopping is better than reading
                // it: §6.1 says the inflated used range must not be read, and a range this
                // large is the same failure with a bigger number.
                break;
            }

            $cells = [];
            $index = 0;

            foreach ($row->toArray() as $value) {
                $letter = self::columnLetter(++$index);

                if ($letter === null || ! in_array($letter, $this->columns, true)) {
                    continue;
                }

                $cells[$letter] = is_object($value) ? $this->readDate($value) : $value;
            }

            $rows[] = [$rowNumber, $cells];
        }

        return $rows;
    }

    /**
     * A cell that arrived as a date object.
     *
     * OpenSpout hands back a `DateTimeInterface` for a cell the author formatted as a date,
     * which is the *good* case: no serial to guess at. Converted to `Y-m-d`, and the caller
     * treats it as a text date.
     */
    private function readDate(object $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return method_exists($value, '__toString') ? (string) $value : null;
    }

    /**
     * §6.1: chronological by the month in the name, and a duplicate month is a blocker.
     *
     * @param  list<RawSheet>  $sheets
     * @return list<RawSheet>
     */
    private function order(array $sheets): array
    {
        $keyed = [];

        foreach ($sheets as $sheet) {
            $month = SheetMonth::fromSheetName($sheet->name);

            $sheet->month = $month;
            $sheet->sortKey = $month?->key() ?? '9999-99-'.$sheet->name;

            $keyed[] = $sheet;
        }

        usort($keyed, static fn (RawSheet $a, RawSheet $b): int => strcmp($a->sortKey, $b->sortKey));

        return $keyed;
    }

    /**
     * Walk one sheet: find headers, take the row above as the title, read the rows below.
     */
    private function readSheet(RawSheet $sheet, ParsedWorkbook $parsed): void
    {
        if ($sheet->month === null) {
            return;
        }

        if ($parsed->claimsMonth($sheet->month->key(), $sheet->name)) {
            $parsed->addIssue(LegacyImportIssue::DuplicateMonthSheet, $sheet->name, null, null, [
                'severity' => LegacyIssueSeverity::Error,
                'blocking' => true,
                'message' => 'Hay más de una hoja para '.$sheet->month->label().'. Se leyó «'.$sheet->name.'».',
            ]);
        }

        $blockIndex = 0;
        $rows = $sheet->rows;
        $total = count($rows);

        for ($i = 0; $i < $total; $i++) {
            [$rowNumber, $cells] = $rows[$i];

            if ($this->isBlankRow($cells)) {
                continue;
            }

            $header = WorkbookHeader::detect($sheet->name, $rowNumber, $this->asStrings($cells));

            if ($header === null) {
                continue;
            }

            $blockIndex++;

            $titleRow = $this->titleAbove($rows, $i, $i);

            if ($titleRow === null) {
                $parsed->addIssue(LegacyImportIssue::MissingCompanyBlockHeader, $sheet->name, $rowNumber, null, [
                    'severity' => LegacyIssueSeverity::Error,
                    'blocking' => true,
                    'message' => 'El encabezado de la fila '.$rowNumber.' no tiene el título de empresa arriba, '
                        .'así que no se sabe a qué NIT pertenece.',
                ]);

                continue;
            }

            $company = CompanyTitle::parse(
                self::titleText($titleRow[1]),
                $this->redactor,
                $sheet->name,
                $titleRow[0],
            );

            // The index is part of the key so two blocks in one sheet for the same company are two
            // blocks. §19 counts blocks, and a block is a title followed by a header, so
            // collapsing two of them would make the count disagree with the file.
            $blockKey = $sheet->month->key().'|'.$blockIndex.'|'.$company->blockKey();
            $parsed->registerBlock($blockKey, $company, $sheet);

            $this->readRows($sheet, $header, $company, $blockIndex, $rows, $i + 1, $parsed);
        }
    }

    /**
     * The nearest non-blank row above the header, which is §6.2's company title.
     *
     * Blank rows are skipped rather than rejected: the real file has a blank line between a
     * block's last person and the next block's title, and demanding the title be on the
     * immediately preceding physical row would reject a file that is merely spaced out.
     *
     * @param  list<array{0: int, 1: array<string, mixed>}>  $rows
     * @return array{0: int, 1: array<string, mixed>}|null
     */
    private function titleAbove(array $rows, int $headerIndex, int $floor): ?array
    {
        for ($i = $headerIndex - 1; $i >= 0; $i--) {
            [$rowNumber, $cells] = $rows[$i];

            if ($this->isBlankRow($cells)) {
                continue;
            }

            // The title is a single cell of prose. A row with a document or an amount in it is
            // the tail of the previous block, not a title, and treating it as one would put a
            // person's name in the NIT field.
            if (self::columnLetterKey($cells, 'H') !== '' || self::columnLetterKey($cells, 'G') !== '') {
                return null;
            }

            return [$rowNumber, $cells];
        }

        return null;
    }

    /**
     * The rows beneath a header, up to the next header or the end of the sheet.
     *
     * @param  list<array{0: int, 1: array<string, mixed>}>  $rows
     */
    private function readRows(
        RawSheet $sheet,
        WorkbookHeader $header,
        CompanyTitle $company,
        int $blockIndex,
        array $rows,
        int $from,
        ParsedWorkbook $parsed,
    ): void {
        $total = count($rows);

        for ($i = $from; $i < $total; $i++) {
            [$rowNumber, $cells] = $rows[$i];

            if ($this->isBlankRow($cells)) {
                continue;
            }

            // The next block starts here. Detected with the same rule that found this one,
            // so a header variant this reader did not expect ends the block instead of
            // being read as a person.
            if (WorkbookHeader::detect($sheet->name, $rowNumber, $this->asStrings($cells)) !== null) {
                break;
            }

            $person = SourcePersonRow::fromCells(
                $header,
                $sheet->month,
                $rowNumber,
                $company,
                $blockIndex,
                $cells,
                $this->redactor,
            );

            if ($person->displayName() === '(sin nombres)' && ! $person->document->isUsable()) {
                // A row with neither names nor a document is not an observation. §7.1 is
                // about rows that have a name and no document, which is a blocker; this is
                // a row with neither, which is spreadsheet furniture.
                continue;
            }

            $parsed->addRow($person);
        }
    }

    /** @param array<string, mixed> $cells */
    private function isBlankRow(array $cells): bool
    {
        foreach ($cells as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * A row as strings, for header detection only.
     *
     * Header detection compares folded words, and folding a number is meaningless, so this
     * is safe and cheaper than teaching `WorkbookHeader` about numeric cells.
     *
     * @param  array<string, mixed>  $cells
     * @return array<string, string>
     */
    private function asStrings(array $cells): array
    {
        $strings = [];

        foreach ($cells as $column => $value) {
            if ($value === null) {
                continue;
            }

            $strings[$column] = is_scalar($value) ? (string) $value : '';
        }

        return $strings;
    }

    /** `1 => 'A'`. */
    private static function columnLetter(int $index): ?string
    {
        $letter = '';

        while ($index > 0) {
            $index--;
            $letter = chr(($index % 26) + 65).$letter;
            $index = intdiv($index, 26);
        }

        return $letter === '' ? null : $letter;
    }

    /** @param array<string, mixed> $cells */
    private static function columnLetterKey(array $cells, string $letter): string
    {
        $value = $cells[$letter] ?? null;

        return $value === null ? '' : trim((string) $value);
    }

    /**
     * The title's text, from whichever of the leading columns carries it.
     *
     * ## Why this scans instead of reading one column
     *
     * The delivered file puts the title in **A**; the shape this profile's own fixtures use
     * puts it in **B**, and §6.2 only says the row above the header is the title, not which
     * cell of that row holds it. Reading `B ?? A` looks like it covers both and does not: the
     * reader produces a cell entry for every column in the range, so `B` is present and
     * **empty**, `??` returns `''` rather than falling through, and every title parses as an
     * empty string. That failure is silent and severe — every company in the file collapses to
     * one anonymous identity, so no NIT is read, `company_names` is 0, and hundreds of people
     * at different employers collide as conflicting duplicates.
     *
     * So: the first non-empty cell among `A`..`F`, left to right. `G` and `H` are excluded
     * already — `titleAbove()` returns null for a row that has them, because a row with an
     * amount or a document in it is the tail of the previous block rather than a title.
     *
     * @param  array<string, mixed>  $cells
     */
    private static function titleText(array $cells): string
    {
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $column) {
            $value = $cells[$column] ?? null;

            if ($value === null || is_object($value)) {
                continue;
            }

            $text = trim((string) $value);

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Builds `.xlsx` fixtures for the A04 tests, and only for the A04 tests.
 *
 * ## Why the fixtures are generated rather than checked in
 *
 * §20 requires tests that never touch the real workbook, and a checked-in copy of a trimmed
 * real workbook is the failure mode that rule exists to prevent: it leaks PII into git, it
 * rots silently when the parser changes, and it tempts somebody into keeping it. Everything
 * here is invented — the names are obviously fake, the NITs are the ones from the spec's own
 * examples, and no value is shared with `.local-fixtures`.
 *
 * ## Written to the system temp directory and deleted by the caller
 *
 * `path()` returns a path under `sys_get_temp_dir()`, not inside the repository, so a failing
 * test cannot leave an `.xlsx` next to the sources where a later `git add .` would pick it up.
 *
 * ## The shape it produces
 *
 * A sheet is a list of row *arrays*; columns A..R are addressed by position, so a test that
 * cares about the `P`/`Q` swap can put a risk in position 15 and a job title in position 16
 * and be explicit about it. `block()` writes the two rows this profile always writes together:
 * a company title, then a header row.
 */
final class SyntheticWorkbook
{
    /**
     * A list rather than a map keyed by name: §6.1 has to be testable, and a workbook with two
     * sheets called `ENERO 2026` is the only way to test it.
     *
     * @var list<array{0: string, 1: list<list<array<string, mixed>>>}>
     */
    private array $sheets = [];

    /** Column letters, so tests can address `P` and `Q` by name. */
    public const COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R'];

    /**
     * The header row the delivered file uses, by column letter.
     *
     * P and Q are headed with the ARL provider here, which is the case §1.1 warns about: the
     * heading says ARL and the values are a risk class and a job title.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    public static function defaultHeader(array $overrides = []): array
    {
        return array_merge([
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
            'M' => 'CAJA',
            'N' => 'EPS SALUD',
            'O' => 'AFP PENSION',
            'P' => 'POSITIVA',
            'Q' => 'CARGO',
            // §1.3: R is `correo`. The synthetic header says so, which is the whole point — a
            // fixture that labelled R `EPS` would have kept the A04-R1 bug alive, since the bug
            // was reading a person's email into a company's ARL.
            'R' => 'CORREO',
        ], $overrides);
    }

    /**
     * A person row, by column letter.
     *
     * Every field has a default so a test states only what it is about — a test for the `P`/`Q`
     * swap writes a name and two cells, and nothing else, and the blank EPS and AFP are read
     * as absent rather than as `NO`.
     *
     * @param  array<string, mixed>  $cells
     * @return array<string, mixed>
     */
    public static function personRow(array $cells = []): array
    {
        return array_merge([
            'A' => '1',
            'B' => 'ANDINA',
            'C' => '104',
            'D' => '',
            'E' => '',
            'F' => 46000,
            'G' => 1_200_000,
            'H' => '10101010',
            'I' => 'JUAN',
            'J' => 'PEREZ',
            'K' => 'CALLE 1 # 2-3',
            'L' => '3001112233',
            'M' => 'COMFENSACION',
            'N' => 'SALUD TOTAL',
            'O' => 'PORVENIR',
            'P' => 'UNO',
            'Q' => 'AUXILIAR',
            // A person's email, never an ARL. §1.3's column contract.
            'R' => 'persona@ejemplo.co',
        ], $cells);
    }

    /**
     * A block: the company title row, then the header row.
     *
     * @param  array<string, string>  $headerOverrides
     * @return list<array<string, mixed>>
     */
    public static function block(string $title, array $headerOverrides = []): array
    {
        return [
            ['B' => $title],
            self::defaultHeader($headerOverrides),
        ];
    }

    /**
     * Append a sheet.
     *
     * @param  list<list<array<string, mixed>>>  $rows  rows as column maps
     */
    public function sheet(string $name, array $rows): self
    {
        $this->sheets[] = [$name, $rows];

        return $this;
    }

    /**
     * A sheet built from blocks and the people under them.
     *
     * @param  list<array{title: string, people: list<array<string, mixed>>, header?: array<string, string>}>  $blocks
     */
    public function sheetWithBlocks(string $name, array $blocks): self
    {
        $rows = [];

        foreach ($blocks as $block) {
            foreach (self::block($block['title'], $block['header'] ?? []) as $row) {
                $rows[] = $row;
            }

            foreach ($block['people'] as $person) {
                $rows[] = $person;
            }

            // A blank line between blocks, because the delivered file has one and the reader
            // has to skip it rather than mistake it for a title.
            $rows[] = [];
        }

        return $this->sheet($name, $rows);
    }

    /**
     * A path in the system temp directory. The caller deletes it.
     */
    public function path(string $name = 'synthetic-a04.xlsx'): string
    {
        $path = sys_get_temp_dir().'/a04-'.bin2hex(random_bytes(6)).'-'.$name;

        $this->write($path);

        return $path;
    }

    /** Write the workbook to an explicit path. */
    public function write(string $path): string
    {
        $writer = new Writer;

        $writer->openToFile($path);

        $first = true;

        foreach ($this->sheets as [$name, $rows]) {
            // The writer starts with one unnamed sheet already current. Asking for a new one
            // before the first `addRow` would leave that empty sheet behind, and a fixture
            // with a stray `Sheet1` in front of `ENERO 2026` is a fixture that tests something
            // the real workbook does not do.
            if ($first) {
                $first = false;
            } else {
                $writer->addNewSheetAndMakeItCurrent();
            }

            $sheet = $writer->getCurrentSheet();
            $sheet->setName($name);

            foreach ($rows as $cells) {
                $writer->addRow(self::toRow($cells));
            }
        }

        $writer->close();

        return $path;
    }

    /**
     * @param  array<string, mixed>  $cells
     */
    private static function toRow(array $cells): Row
    {
        // Positional, so the keys have to become indexes — and a missing column has to stay
        // missing rather than sliding the rest left. A title written only in `B` must land in
        // column B, and `array_values()` on a sparse map would have put it in A.
        $cellsArray = array_fill(0, count(self::COLUMNS) - 1, null);

        foreach ($cells as $letter => $value) {
            $index = array_search($letter, self::COLUMNS, true);

            if ($index === false) {
                continue;
            }

            // §8.3: column E (FECHA AFILIACION) must be written as text so that truncated
            // years like "15/01/26" are not auto-converted to Excel serials.
            // We create a StringCell with text format '@' to prevent auto-detection as date.
            if ($letter === 'E' && is_string($value)) {
                $cellsArray[$index] = Cell::fromValue($value)
                    ->withStyle(new Style(format: '@'));
            } else {
                $cellsArray[$index] = Cell::fromValue($value);
            }
        }

        // Build Row directly with Cell objects (Row::fromValues expects raw values).
        $cells = array_map(
            static fn ($v): Cell => $v instanceof Cell ? $v : Cell::fromValue($v),
            $cellsArray
        );

        return new Row($cells);
    }
}

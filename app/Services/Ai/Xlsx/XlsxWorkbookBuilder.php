<?php

declare(strict_types=1);

namespace App\Services\Ai\Xlsx;

use App\Domain\Ai\AiToolRejected;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Builds a real XLSX workbook from the `create_artifact` payload.
 *
 * Two formats are accepted:
 *
 *  - Legacy: a JSON array of rows, exactly as before. It produces one `Datos`
 *    sheet, preserving the contract every existing caller uses.
 *  - Version 2: `{"version":2,"sheets":[{"name":"Cartera","rows":[...]}]}`.
 *    Each sheet can define rows, column/cell number formats and a freeze pane.
 *    A cell may be a scalar or an explicit typed cell, including a validated
 *    formula.
 *
 * A bare JSON object, malformed JSON or an unsupported object is rejected. It
 * is never reinterpreted as CSV: that was the defect that turned one JSON
 * object into forty-two cells of fragments.
 */
final class XlsxWorkbookBuilder
{
    public const MAX_SHEETS = 20;

    public const MAX_ROWS = 5000;

    public const MAX_COLUMNS = 100;

    public const MAX_CELLS = 50_000;

    public const MAX_FORMULAS = 5000;

    public const MAX_FORMULA_LENGTH = 8192;

    public const MAX_TEXT_LENGTH = 32_767;

    private const FORMAT_CODES = [
        'general' => 'General',
        'number' => '#,##0',
        'integer' => '0',
        'currency_cop' => '"$" #,##0;[Red]-"$" #,##0',
        'currency' => '"$" #,##0;[Red]-"$" #,##0',
        'percentage' => '0.00%',
        'percent' => '0.00%',
        'text' => '@',
    ];

    public function __construct(private readonly FormulaValidator $formulaValidator) {}

    /**
     * Build the XLSX bytes and return them.
     */
    public function build(string $content): string
    {
        $book = $this->parse($content);

        $spreadsheet = new Spreadsheet;
        $formulaCells = [];

        try {
            $first = true;

            foreach ($book['sheets'] as $sheet) {
                $worksheet = $first
                    ? $spreadsheet->getActiveSheet()
                    : $spreadsheet->createSheet();

                $first = false;
                $worksheet->setTitle($sheet['name']);
                $formulaCells[$sheet['name']] = $this->writeSheet($worksheet, $sheet);
            }

            $spreadsheet->setActiveSheetIndex(0);

            $this->assertFormulasCalculate($spreadsheet, $formulaCells);

            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(true);

            $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');

            if ($tempFile === false) {
                throw new AiToolRejected('No se pudo preparar el archivo XLSX.');
            }

            try {
                $writer->save($tempFile);
                $bytes = file_get_contents($tempFile);

                if ($bytes === false || $bytes === '') {
                    throw new AiToolRejected('No se pudo escribir el archivo XLSX.');
                }

                return $bytes;
            } finally {
                if (is_file($tempFile)) {
                    unlink($tempFile);
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * Strict legacy reader used by CSV and by the legacy XLSX branch.
     *
     * @return list<array<int, mixed>>
     */
    public function legacyRows(string $content, string $format = 'XLSX'): array
    {
        $trimmed = ltrim($content);

        if ($trimmed === '') {
            throw new AiToolRejected("El contenido para {$format} está vacío.");
        }

        if ($this->looksLikeJson($trimmed)) {
            $decoded = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new AiToolRejected(
                    "El contenido para {$format} parece JSON pero está mal formado. "
                    .'No se interpretó como CSV.'
                );
            }

            if (! is_array($decoded)) {
                throw new AiToolRejected("El contenido para {$format} debe ser un arreglo JSON de filas.");
            }

            if (! array_is_list($decoded)) {
                if (($decoded['version'] ?? null) === 2) {
                    throw new AiToolRejected("El formato {$format} no admite varias hojas. Usa XLSX para un libro v2.");
                }

                throw new AiToolRejected(
                    "El objeto JSON para {$format} no es compatible. "
                    .'Usa un arreglo de filas o el formato XLSX v2 con «sheets».'
                );
            }

            return $this->normaliseLegacyRows($decoded, $format);
        }

        $rows = $this->rowsFromText($content);

        if ($rows === []) {
            throw new AiToolRejected("El contenido para {$format} no contiene filas.");
        }

        return $this->normaliseLegacyRows($rows, $format);
    }

    /**
     * @return array{sheets: list<array{
     *     name: string,
     *     rows: list<list<array{kind: string, value?: mixed, expression?: string, format?: string}>>,
     *     column_formats: array<string, string>,
     *     cell_formats: array<string, string>,
     *     freeze_pane: string|null
     * }>}
     */
    private function parse(string $content): array
    {
        $trimmed = ltrim($content);

        if ($trimmed === '') {
            throw new AiToolRejected('El contenido para XLSX está vacío.');
        }

        if (! $this->looksLikeJson($trimmed)) {
            $rows = $this->rowsFromText($content);

            if ($rows === []) {
                throw new AiToolRejected('El contenido para XLSX no contiene filas.');
            }

            return $this->legacyBook($this->normaliseLegacyRows($rows, 'XLSX'));
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AiToolRejected(
                'El contenido para XLSX parece JSON pero está mal formado. No se interpretó como CSV.'
            );
        }

        if (! is_array($decoded)) {
            throw new AiToolRejected('El contenido para XLSX debe ser un arreglo de filas o un libro v2.');
        }

        if (array_is_list($decoded)) {
            return $this->legacyBook($this->normaliseLegacyRows($decoded, 'XLSX'));
        }

        if (($decoded['version'] ?? null) !== 2) {
            throw new AiToolRejected(
                'El objeto JSON para XLSX debe incluir «version»: 2 y una lista «sheets» no vacía.'
            );
        }

        if (! isset($decoded['sheets']) || ! is_array($decoded['sheets']) || ! array_is_list($decoded['sheets'])) {
            throw new AiToolRejected('El libro XLSX v2 debe incluir una lista «sheets» no vacía.');
        }

        if ($decoded['sheets'] === []) {
            throw new AiToolRejected('El libro XLSX v2 debe incluir al menos una hoja.');
        }

        if (count($decoded['sheets']) > self::MAX_SHEETS) {
            throw new AiToolRejected('El libro XLSX supera el máximo de '.self::MAX_SHEETS.' hojas.');
        }

        $book = ['sheets' => []];
        $names = [];
        $totalCells = 0;
        $totalFormulas = 0;

        foreach ($decoded['sheets'] as $index => $rawSheet) {
            if (! is_array($rawSheet) || array_is_list($rawSheet)) {
                throw new AiToolRejected('Cada elemento de «sheets» debe ser un objeto con nombre y filas.');
            }

            $name = $this->validSheetName($rawSheet['name'] ?? null, $index);
            $key = mb_strtolower($name);

            if (isset($names[$key])) {
                throw new AiToolRejected("El libro XLSX tiene hojas duplicadas: «{$name}».");
            }

            $names[$key] = true;

            if (! isset($rawSheet['rows']) || ! is_array($rawSheet['rows']) || ! array_is_list($rawSheet['rows'])) {
                throw new AiToolRejected("La hoja «{$name}» debe incluir una lista «rows».");
            }

            $rawRows = $rawSheet['rows'];

            if ($rawRows === []) {
                throw new AiToolRejected("La hoja «{$name}» no tiene filas.");
            }

            if (count($rawRows) > self::MAX_ROWS) {
                throw new AiToolRejected("La hoja «{$name}» supera el máximo de ".self::MAX_ROWS.' filas.');
            }

            $rows = [];
            $maxColumns = 0;

            foreach ($rawRows as $rowIndex => $rawRow) {
                if (! is_array($rawRow) || ! array_is_list($rawRow)) {
                    throw new AiToolRejected(
                        'La fila '.($rowIndex + 1)." de la hoja «{$name}» debe ser una lista de celdas."
                    );
                }

                if (count($rawRow) > self::MAX_COLUMNS) {
                    throw new AiToolRejected("La hoja «{$name}» supera el máximo de ".self::MAX_COLUMNS.' columnas.');
                }

                $row = [];

                foreach ($rawRow as $columnIndex => $rawCell) {
                    $cell = $this->normaliseCell($rawCell, $name, $rowIndex + 1, $columnIndex + 1);
                    $row[] = $cell;

                    if ($cell['kind'] === 'formula') {
                        $totalFormulas++;
                    }
                }

                $totalCells += count($row);
                $maxColumns = max($maxColumns, count($row));
                $rows[] = $row;
            }

            if ($totalCells > self::MAX_CELLS) {
                throw new AiToolRejected('El libro XLSX supera el máximo de '.self::MAX_CELLS.' celdas.');
            }

            if ($totalFormulas > self::MAX_FORMULAS) {
                throw new AiToolRejected('El libro XLSX supera el máximo de '.self::MAX_FORMULAS.' fórmulas.');
            }

            if ($maxColumns === 0) {
                throw new AiToolRejected("La hoja «{$name}» no contiene celdas.");
            }

            $book['sheets'][] = [
                'name' => $name,
                'rows' => $rows,
                'column_formats' => $this->normaliseColumnFormats($rawSheet['column_formats'] ?? [], $name, $maxColumns),
                'cell_formats' => $this->normaliseCellFormats(
                    $rawSheet['cell_formats'] ?? [],
                    $name,
                    count($rows),
                    $maxColumns
                ),
                'freeze_pane' => $this->normaliseFreezePane($rawSheet['freeze_pane'] ?? null, count($rows)),
            ];
        }

        $this->validateFormulas($book);
        $this->applyIndicators(
            $book,
            $decoded['indicators'] ?? null,
            $decoded['required_indicators'] ?? null,
        );
        $this->validatePercentageFormats($book);

        return $book;
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     * @return array{sheets: list<array{name: string, rows: list<list<array{kind: string, value: mixed}>>, column_formats: array<string, string>, cell_formats: array<string, string>, freeze_pane: string|null}>}
     */
    private function legacyBook(array $rows): array
    {
        $normalised = [];

        foreach ($rows as $row) {
            $cells = [];

            foreach ($row as $cell) {
                $cells[] = ['kind' => 'value', 'value' => $cell];
            }

            $normalised[] = $cells;
        }

        return [
            'sheets' => [[
                'name' => 'Datos',
                'rows' => $normalised,
                'column_formats' => [],
                'cell_formats' => [],
                'freeze_pane' => count($normalised) > 1 ? 'A2' : null,
            ]],
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array<int, mixed>>
     */
    private function normaliseLegacyRows(array $rows, string $format): array
    {
        if ($rows === []) {
            throw new AiToolRejected("El contenido para {$format} debe tener al menos una fila.");
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new AiToolRejected("El contenido para {$format} supera el máximo de ".self::MAX_ROWS.' filas.');
        }

        $normalised = [];
        $cells = 0;

        foreach ($rows as $rowIndex => $row) {
            if (is_array($row) && ! array_is_list($row)) {
                throw new AiToolRejected(
                    'La fila '.($rowIndex + 1)." de {$format} es un objeto JSON, no una lista de celdas."
                );
            }

            $source = is_array($row) ? $row : [$row];

            if (count($source) > self::MAX_COLUMNS) {
                throw new AiToolRejected("El contenido para {$format} supera el máximo de ".self::MAX_COLUMNS.' columnas.');
            }

            $cellsInRow = [];

            foreach ($source as $cell) {
                if (is_array($cell) || is_object($cell)) {
                    throw new AiToolRejected(
                        'La fila '.($rowIndex + 1)." de {$format} contiene un objeto donde se esperaba un valor."
                    );
                }

                if (! is_scalar($cell) && $cell !== null) {
                    throw new AiToolRejected(
                        'La fila '.($rowIndex + 1)." de {$format} contiene un tipo de celda no admitido."
                    );
                }

                $cellsInRow[] = $cell;
            }

            $cells += count($cellsInRow);

            if ($cells > self::MAX_CELLS) {
                throw new AiToolRejected("El contenido para {$format} supera el máximo de ".self::MAX_CELLS.' celdas.');
            }

            $normalised[] = $cellsInRow;
        }

        $columnCount = 0;

        foreach ($normalised as $row) {
            $columnCount = max($columnCount, count($row));
        }

        if ($columnCount === 0) {
            throw new AiToolRejected("El contenido para {$format} no contiene celdas.");
        }

        return $normalised;
    }

    /**
     * @return array{kind: string, value?: mixed, expression?: string, format?: string}
     */
    private function normaliseCell(mixed $cell, string $sheet, int $row, int $column): array
    {
        if ($cell === null) {
            return ['kind' => 'blank'];
        }

        if (! is_array($cell)) {
            if (! is_scalar($cell)) {
                throw new AiToolRejected(
                    'La celda '.$this->coordinate($row, $column)." de la hoja «{$sheet}» contiene un tipo no admitido."
                );
            }

            if (is_string($cell)) {
                $this->assertTextLength($cell, $sheet, $row, $column);
            }

            return ['kind' => 'value', 'value' => $cell];
        }

        if (array_is_list($cell) || ! isset($cell['type'])) {
            throw new AiToolRejected(
                'La celda '.$this->coordinate($row, $column)." de la hoja «{$sheet}» "
                .'contiene un objeto no admitido. Usa un valor o {"type":"formula","expression":"=..."}.'
            );
        }

        $type = mb_strtolower((string) $cell['type']);

        $normalised = match ($type) {
            'formula' => $this->normaliseFormulaCell($cell, $sheet, $row, $column),
            'text', 'string' => $this->normaliseTextCell($cell, $sheet, $row, $column),
            'number', 'integer' => $this->normaliseNumberCell($cell, $sheet, $row, $column),
            'boolean', 'bool' => $this->normaliseBooleanCell($cell, $sheet, $row, $column),
            'blank' => ['kind' => 'blank'],
            default => throw new AiToolRejected(
                'La celda '.$this->coordinate($row, $column)." de la hoja «{$sheet}» "
                ."tiene un tipo no admitido: «{$type}»."
            ),
        };

        if (isset($cell['format'])) {
            if ($normalised['kind'] === 'blank') {
                throw new AiToolRejected(
                    'La celda '.$this->coordinate($row, $column)." de la hoja «{$sheet}» no puede tener formato si está vacía."
                );
            }

            $normalised['format'] = $this->normaliseFormatName($cell['format']);
        }

        return $normalised;
    }

    /**
     * @param  array<string, mixed>  $cell
     * @return array{kind: string, expression: string}
     */
    private function normaliseFormulaCell(array $cell, string $sheet, int $row, int $column): array
    {
        $expression = $cell['expression'] ?? null;

        if (! is_string($expression) || trim($expression) === '') {
            throw new AiToolRejected(
                'La fórmula de la celda '.$this->coordinate($row, $column)." de la hoja «{$sheet}» está vacía."
            );
        }

        if (strlen($expression) > self::MAX_FORMULA_LENGTH) {
            throw new AiToolRejected('Una fórmula del libro XLSX supera el máximo de '.self::MAX_FORMULA_LENGTH.' caracteres.');
        }

        return ['kind' => 'formula', 'expression' => $expression];
    }

    /**
     * @param  array<string, mixed>  $cell
     * @return array{kind: string, value: string}
     */
    private function normaliseTextCell(array $cell, string $sheet, int $row, int $column): array
    {
        $value = $cell['value'] ?? null;

        if (is_array($value) || is_object($value)) {
            throw new AiToolRejected(
                'La celda de texto '.$this->coordinate($row, $column)." de la hoja «{$sheet}» no contiene un valor simple."
            );
        }

        $text = (string) ($value ?? '');
        $this->assertTextLength($text, $sheet, $row, $column);

        return ['kind' => 'text', 'value' => $text];
    }

    /**
     * @param  array<string, mixed>  $cell
     * @return array{kind: string, value: int|float}
     */
    private function normaliseNumberCell(array $cell, string $sheet, int $row, int $column): array
    {
        $value = $cell['value'] ?? null;

        if (is_bool($value) || ! is_numeric($value)) {
            throw new AiToolRejected(
                'La celda numérica '.$this->coordinate($row, $column)." de la hoja «{$sheet}» no contiene un número."
            );
        }

        return ['kind' => 'number', 'value' => $value + 0];
    }

    /**
     * @param  array<string, mixed>  $cell
     * @return array{kind: string, value: bool}
     */
    private function normaliseBooleanCell(array $cell, string $sheet, int $row, int $column): array
    {
        $value = $cell['value'] ?? null;

        if (! is_bool($value)) {
            throw new AiToolRejected(
                'La celda booleana '.$this->coordinate($row, $column)." de la hoja «{$sheet}» no contiene true/false."
            );
        }

        return ['kind' => 'boolean', 'value' => $value];
    }

    private function validSheetName(mixed $name, int $index): string
    {
        if (! is_string($name)) {
            throw new AiToolRejected('La hoja '.($index + 1).' del libro XLSX no tiene un nombre válido.');
        }

        $name = trim($name);

        if ($name === '') {
            throw new AiToolRejected('El libro XLSX contiene una hoja sin nombre.');
        }

        if (mb_strlen($name) > 31) {
            throw new AiToolRejected("El nombre de hoja «{$name}» supera los 31 caracteres de Excel.");
        }

        if (preg_match('/[\\\\\\/?*\\[\\]:]/u', $name) === 1) {
            throw new AiToolRejected("El nombre de hoja «{$name}» contiene caracteres no permitidos por Excel.");
        }

        if (preg_match('/^[\\p{L}\\p{N} _.\\-]+$/u', $name) !== 1) {
            throw new AiToolRejected("El nombre de hoja «{$name}» contiene caracteres no admitidos.");
        }

        if (mb_strtolower($name) === 'history') {
            throw new AiToolRejected('El nombre de hoja «History» está reservado por Excel.');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $formats
     * @return array<string, string>
     */
    private function normaliseColumnFormats(mixed $formats, string $sheet, int $maxColumns): array
    {
        if (! is_array($formats)) {
            throw new AiToolRejected("El campo «column_formats» de la hoja «{$sheet}» debe ser un objeto.");
        }

        if ($formats !== [] && array_is_list($formats)) {
            throw new AiToolRejected("El campo «column_formats» de la hoja «{$sheet}» debe usar letras de columna como claves.");
        }

        $normalised = [];

        foreach ($formats as $column => $format) {
            $column = strtoupper(trim((string) $column));

            if (preg_match('/^[A-Z]{1,3}$/', $column) !== 1) {
                throw new AiToolRejected("La columna «{$column}» de «column_formats» no es válida.");
            }

            $index = Coordinate::columnIndexFromString($column);

            if ($index > $maxColumns) {
                throw new AiToolRejected("La columna «{$column}» de la hoja «{$sheet}» está fuera de los datos.");
            }

            $normalised[$column] = $this->normaliseFormatName($format);
        }

        return $normalised;
    }

    /**
     * @param  array<string, mixed>  $formats
     * @return array<string, string>
     */
    private function normaliseCellFormats(mixed $formats, string $sheet, int $maxRows, int $maxColumns): array
    {
        if (! is_array($formats)) {
            throw new AiToolRejected("El campo «cell_formats» de la hoja «{$sheet}» debe ser un objeto.");
        }

        if ($formats !== [] && array_is_list($formats)) {
            throw new AiToolRejected("El campo «cell_formats» de la hoja «{$sheet}» debe usar coordenadas como claves.");
        }

        $normalised = [];

        foreach ($formats as $coordinate => $format) {
            $coordinate = strtoupper(str_replace('$', '', trim((string) $coordinate)));

            if (preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/', $coordinate, $match) !== 1) {
                throw new AiToolRejected("La coordenada «{$coordinate}» de «cell_formats» no es válida.");
            }

            $column = Coordinate::columnIndexFromString($match[1]);
            $row = (int) $match[2];

            if ($row > $maxRows || $column > $maxColumns) {
                throw new AiToolRejected("La celda «{$coordinate}» de la hoja «{$sheet}» está fuera de los datos.");
            }

            $normalised[$coordinate] = $this->normaliseFormatName($format);
        }

        return $normalised;
    }

    private function normaliseFreezePane(mixed $freezePane, int $rows): ?string
    {
        if ($freezePane === null) {
            return $rows > 1 ? 'A2' : null;
        }

        if (! is_string($freezePane)) {
            throw new AiToolRejected('El campo «freeze_pane» debe ser una coordenada como A2.');
        }

        $freezePane = strtoupper(trim($freezePane));

        if (preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/', $freezePane, $match) !== 1) {
            throw new AiToolRejected("La coordenada de «freeze_pane» «{$freezePane}» no es válida.");
        }

        $column = Coordinate::columnIndexFromString($match[1]);
        $row = (int) $match[2];

        if ($column > 16384 || $row > 1048576) {
            throw new AiToolRejected("La coordenada de «freeze_pane» «{$freezePane}» está fuera de Excel.");
        }

        return $freezePane;
    }

    private function normaliseFormatName(mixed $format): string
    {
        if (! is_string($format)) {
            throw new AiToolRejected('El formato de celda debe ser una cadena.');
        }

        $key = mb_strtolower(trim($format));

        if (! isset(self::FORMAT_CODES[$key])) {
            throw new AiToolRejected(
                "El formato «{$format}» no está admitido. Usa currency_cop, percentage, number, integer, text o general."
            );
        }

        return self::FORMAT_CODES[$key];
    }

    /**
     * @param  array{sheets: list<array{name: string, rows: list<list<array{kind: string, value?: mixed, expression?: string, format?: string}>>, column_formats: array<string, string>, cell_formats: array<string, string>, freeze_pane: string|null}>}  $book
     */
    private function validateFormulas(array &$book): void
    {
        $names = array_map(fn (array $sheet): string => $sheet['name'], $book['sheets']);
        $bounds = [];

        foreach ($book['sheets'] as $sheet) {
            $columns = 0;

            foreach ($sheet['rows'] as $row) {
                $columns = max($columns, count($row));
            }

            $bounds[$sheet['name']] = ['rows' => count($sheet['rows']), 'cols' => $columns];
        }

        foreach ($book['sheets'] as &$sheet) {
            foreach ($sheet['rows'] as $rowIndex => &$row) {
                foreach ($row as $columnIndex => &$cell) {
                    if ($cell['kind'] !== 'formula') {
                        continue;
                    }

                    try {
                        $cell['expression'] = $this->formulaValidator->validate(
                            $cell['expression'],
                            $sheet['name'],
                            $bounds,
                            $names,
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw new AiToolRejected(
                            'Fórmula rechazada en la hoja «'.$sheet['name'].'», celda '
                            .$this->coordinate($rowIndex + 1, $columnIndex + 1).': '
                            .$exception->getMessage()
                        );
                    }
                }
            }

            unset($row);
        }

        unset($sheet);
    }

    /**
     * Validate the stable summary indicators declared by the model and apply
     * their explicit formats to the destination cells.
     *
     * `required_indicators` is the machine-readable list of indicators the user
     * asked for. `indicators` defines each one by stable id, sheet, cell, kind
     * and format. Every required id must have a definition whose destination
     * exists; otherwise the workbook is rejected. This is how an omitted
     * "descuento total" or "saldo ajustado" stops being silently accepted.
     */
    private function applyIndicators(array &$book, mixed $rawIndicators, mixed $rawRequired): void
    {
        if ($rawIndicators === null && $rawRequired === null) {
            return;
        }

        if ($rawIndicators === null || $rawRequired === null) {
            throw new AiToolRejected(
                'El libro XLSX debe enviar «required_indicators» e «indicators» juntos.'
            );
        }

        if (! is_array($rawIndicators) || ! array_is_list($rawIndicators)) {
            throw new AiToolRejected('El campo «indicators» debe ser una lista de indicadores.');
        }

        if (! is_array($rawRequired) || ! array_is_list($rawRequired)) {
            throw new AiToolRejected('El campo «required_indicators» debe ser una lista de identificadores.');
        }

        if ($rawIndicators === [] && $rawRequired === []) {
            return;
        }

        $required = [];

        foreach ($rawRequired as $rawId) {
            $id = $this->normaliseIndicatorId($rawId, 'required_indicators');

            if (isset($required[$id])) {
                throw new AiToolRejected("El identificador de indicador «{$id}» está duplicado en required_indicators.");
            }

            $required[$id] = true;
        }

        if ($required === []) {
            throw new AiToolRejected('Si defines indicadores, «required_indicators» no puede estar vacío.');
        }

        $sheetIndexes = [];

        foreach ($book['sheets'] as $index => $sheet) {
            $sheetIndexes[mb_strtolower($sheet['name'])] = $index;
        }

        $defined = [];

        foreach ($rawIndicators as $raw) {
            if (! is_array($raw) || array_is_list($raw)) {
                throw new AiToolRejected('Cada elemento de «indicators» debe ser un objeto.');
            }

            $id = $this->normaliseIndicatorId($raw['id'] ?? null, 'indicators');

            if (isset($defined[$id])) {
                throw new AiToolRejected("El indicador «{$id}» está definido más de una vez.");
            }

            $sheetName = $raw['sheet'] ?? null;

            if (! is_string($sheetName) || trim($sheetName) === '') {
                throw new AiToolRejected("El indicador «{$id}» debe indicar una hoja.");
            }

            $sheetKey = mb_strtolower(trim($sheetName));

            if (! isset($sheetIndexes[$sheetKey])) {
                throw new AiToolRejected("El indicador «{$id}» apunta a una hoja inexistente: «{$sheetName}».");
            }

            $sheet = &$book['sheets'][$sheetIndexes[$sheetKey]];
            $coordinate = strtoupper(str_replace('$', '', trim((string) ($raw['cell'] ?? ''))));

            if (preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/', $coordinate, $match) !== 1) {
                throw new AiToolRejected("El indicador «{$id}» debe indicar una celda válida como B2.");
            }

            $columnIndex = Coordinate::columnIndexFromString($match[1]);
            $rowIndex = (int) $match[2];
            $maxColumns = 0;

            foreach ($sheet['rows'] as $row) {
                $maxColumns = max($maxColumns, count($row));
            }

            if ($rowIndex > count($sheet['rows']) || $columnIndex > $maxColumns) {
                throw new AiToolRejected(
                    "El indicador «{$id}» apunta a {$coordinate}, fuera de los datos de la hoja «{$sheet['name']}»."
                );
            }

            if (! isset($sheet['rows'][$rowIndex - 1][$columnIndex - 1])) {
                throw new AiToolRejected("El indicador «{$id}» apunta a una celda vacía en «{$sheet['name']}».");
            }

            $cell = &$sheet['rows'][$rowIndex - 1][$columnIndex - 1];

            if ($cell['kind'] === 'blank') {
                throw new AiToolRejected("El indicador «{$id}» apunta a una celda vacía en «{$sheet['name']}».");
            }

            $kind = $raw['kind'] ?? null;

            if (! is_string($kind) || ! in_array(mb_strtolower(trim($kind)), ['formula', 'value'], true)) {
                throw new AiToolRejected(
                    "El indicador «{$id}» debe declarar kind como «formula» o «value»."
                );
            }

            $kind = mb_strtolower(trim($kind));

            if ($kind === 'formula' && $cell['kind'] !== 'formula') {
                throw new AiToolRejected("El indicador «{$id}» se declaró como fórmula, pero {$coordinate} no lo es.");
            }

            if ($kind === 'value' && $cell['kind'] === 'formula') {
                throw new AiToolRejected("El indicador «{$id}» se declaró como valor, pero {$coordinate} es una fórmula.");
            }

            if (! array_key_exists('format', $raw) || ! is_string($raw['format']) || trim($raw['format']) === '') {
                throw new AiToolRejected("El indicador «{$id}» debe declarar un format explícito.");
            }

            $format = $this->normaliseFormatName($raw['format']);

            // The indicator declaration is the most specific specification:
            // it overrides a column default and a cell-level default.
            $cell['format'] = $format;

            $this->assertNumericIndicator($cell, $format, $id, $sheet['name'], $coordinate);

            $defined[$id] = true;

            unset($cell, $sheet);
        }

        $missing = array_values(array_diff(array_keys($required), array_keys($defined)));

        if ($missing !== []) {
            throw new AiToolRejected(
                'Faltan indicadores requeridos en el libro XLSX: '.implode(', ', $missing).'.'
            );
        }
    }

    private function normaliseIndicatorId(mixed $id, string $field): string
    {
        if (! is_string($id)) {
            throw new AiToolRejected("Cada identificador de «{$field}» debe ser una cadena.");
        }

        $id = trim($id);

        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $id) !== 1) {
            throw new AiToolRejected(
                "El identificador de indicador «{$id}» no es válido. Usa minúsculas, números, punto, guion o guion bajo."
            );
        }

        return $id;
    }

    /**
     * Money, integer and percentage indicators must be numeric or formulas. A
     * text cell such as "33,33 %" is not a real percentage value.
     */
    private function assertNumericIndicator(
        array $cell,
        string $format,
        string $id,
        string $sheet,
        string $coordinate
    ): void {
        $numericFormats = [
            self::FORMAT_CODES['currency_cop'],
            self::FORMAT_CODES['integer'],
            self::FORMAT_CODES['percentage'],
        ];

        if (! in_array($format, $numericFormats, true)) {
            return;
        }

        if ($cell['kind'] === 'formula') {
            return;
        }

        $value = null;

        if ($cell['kind'] === 'number') {
            $value = $cell['value'];
        } elseif ($cell['kind'] === 'value' && is_numeric($cell['value'] ?? null)) {
            $value = $cell['value'] + 0;
        }

        if ($value === null) {
            throw new AiToolRejected(
                "El indicador «{$id}» de la hoja «{$sheet}», celda {$coordinate}, "
                .'debe ser numérico o una fórmula para su formato.'
            );
        }

        if ($format === self::FORMAT_CODES['integer'] && (float) $value !== floor((float) $value)) {
            throw new AiToolRejected(
                "El indicador «{$id}» de la hoja «{$sheet}», celda {$coordinate}, debe ser un entero."
            );
        }
    }

    /**
     * A percentage cell stores a fraction; the number format displays it as a
     * percentage. Reject the two obvious ways to break that contract: a formula
     * that already multiplies by 100 (or divides by 0.01), and text pretending
     * to be a percentage.
     */
    private function validatePercentageFormats(array $book): void
    {
        foreach ($book['sheets'] as $sheet) {
            foreach ($sheet['rows'] as $rowIndex => $row) {
                foreach ($row as $columnIndex => $cell) {
                    $coordinate = $this->coordinate($rowIndex + 1, $columnIndex + 1);
                    $format = $cell['format'] ?? $sheet['cell_formats'][$coordinate] ?? null;

                    if ($format === null && $rowIndex > 0) {
                        $column = Coordinate::stringFromColumnIndex($columnIndex + 1);
                        $format = $sheet['column_formats'][$column] ?? null;
                    }

                    if ($format !== self::FORMAT_CODES['percentage'] || $cell['kind'] === 'blank') {
                        continue;
                    }

                    if ($cell['kind'] === 'formula') {
                        if ($this->formulaValidator->isScaledByHundred($cell['expression'])) {
                            throw new AiToolRejected(
                                "La celda {$coordinate} de la hoja «{$sheet['name']}» tiene formato percentage, "
                                .'pero su fórmula multiplica por 100. Guarda la fracción (0,3333) y deja que '
                                .'el formato la muestre como 33,33 %.'
                            );
                        }

                        continue;
                    }

                    if ($cell['kind'] === 'number') {
                        continue;
                    }

                    if ($cell['kind'] === 'value' && is_numeric($cell['value'] ?? null)) {
                        continue;
                    }

                    throw new AiToolRejected(
                        "La celda {$coordinate} de la hoja «{$sheet['name']}» tiene formato percentage, "
                        .'pero no contiene un número o una fórmula.'
                    );
                }
            }
        }
    }

    /**
     * @param  array{name: string, rows: list<list<array{kind: string, value?: mixed, expression?: string, format?: string}>>, column_formats: array<string, string>, cell_formats: array<string, string>, freeze_pane: string|null}  $sheet
     * @return array<string, string> coordinate => expression
     */
    private function writeSheet(Worksheet $worksheet, array $sheet): array
    {
        $rows = $sheet['rows'];
        $maxRows = count($rows);
        $maxColumns = 0;
        $formulaCells = [];

        foreach ($rows as $rowIndex => $row) {
            $maxColumns = max($maxColumns, count($row));

            foreach ($row as $columnIndex => $cell) {
                $coordinate = $this->coordinate($rowIndex + 1, $columnIndex + 1);
                $this->writeCell($worksheet, $coordinate, $cell);

                if ($cell['kind'] === 'formula') {
                    $formulaCells[$coordinate] = $cell['expression'];
                }
            }
        }

        if ($maxColumns === 0) {
            return [];
        }

        $lastColumn = Coordinate::stringFromColumnIndex($maxColumns);
        $lastRow = $maxRows;

        $worksheet->getStyle('A1:'.$lastColumn.$lastRow)->getFont()->setSize(11);

        $worksheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        $worksheet->getStyle('A1:'.$lastColumn.'1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('EEF2F7');
        $worksheet->getStyle('A1:'.$lastColumn.'1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $worksheet->getStyle('A1:'.$lastColumn.'1')->getBorders()
            ->getBottom()->setBorderStyle(Border::BORDER_THIN);

        foreach ($rows as $rowIndex => $row) {
            if (! $this->isTotalRow($row)) {
                continue;
            }

            $range = 'A'.($rowIndex + 1).':'.$lastColumn.($rowIndex + 1);
            $worksheet->getStyle($range)->getFont()->setBold(true);
            $worksheet->getStyle($range)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('F8FAFC');
            $worksheet->getStyle($range)->getBorders()
                ->getTop()->setBorderStyle(Border::BORDER_THIN);
        }

        foreach ($sheet['column_formats'] as $column => $format) {
            $start = $maxRows > 1 ? 2 : 1;
            $worksheet->getStyle($column.$start.':'.$column.$maxRows)
                ->getNumberFormat()->setFormatCode($format);
        }

        foreach ($sheet['cell_formats'] as $coordinate => $format) {
            $worksheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($format);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $cell) {
                if (isset($cell['format']) && $cell['kind'] !== 'blank') {
                    // Typed cells can carry their own format; written last so it
                    // wins over a column default.
                    $worksheet->getStyle($this->coordinate($rowIndex + 1, $columnIndex + 1))
                        ->getNumberFormat()->setFormatCode($cell['format']);
                }
            }
        }

        if ($sheet['freeze_pane'] !== null) {
            $worksheet->freezePane($sheet['freeze_pane']);
        }

        for ($index = 1; $index <= $maxColumns; $index++) {
            $worksheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }

        return $formulaCells;
    }

    /**
     * @param  array{kind: string, value?: mixed, expression?: string, format?: string}  $cell
     */
    private function writeCell(Worksheet $worksheet, string $coordinate, array $cell): void
    {
        switch ($cell['kind']) {
            case 'blank':
                return;

            case 'formula':
                $worksheet->setCellValueExplicit(
                    $coordinate,
                    '='.$cell['expression'],
                    DataType::TYPE_FORMULA
                );

                return;

            case 'text':
                $worksheet->setCellValueExplicit(
                    $coordinate,
                    $this->safeText($cell['value']),
                    DataType::TYPE_STRING
                );

                return;

            case 'number':
                $worksheet->setCellValueExplicit($coordinate, $cell['value'], DataType::TYPE_NUMERIC);

                return;

            case 'boolean':
                $worksheet->setCellValueExplicit($coordinate, $cell['value'], DataType::TYPE_BOOL);

                return;

            default:
                $value = $cell['value'] ?? null;

                if ($value === null || $value === '') {
                    $worksheet->setCellValueExplicit($coordinate, '', DataType::TYPE_STRING);

                    return;
                }

                if (is_int($value) || is_float($value)) {
                    $worksheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);

                    return;
                }

                if (is_bool($value)) {
                    $worksheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_BOOL);

                    return;
                }

                $text = (string) $value;

                if (preg_match('/^[=+\-@\t\r]/u', $text) === 1) {
                    $worksheet->setCellValueExplicit($coordinate, "'".$text, DataType::TYPE_STRING);

                    return;
                }

                $worksheet->setCellValue($coordinate, $text);
        }
    }

    /**
     * @param  list<array{kind: string, value?: mixed, expression?: string, format?: string}>  $row
     */
    private function isTotalRow(array $row): bool
    {
        $first = $row[0] ?? null;

        if ($first === null || ! in_array($first['kind'], ['value', 'text'], true) || ! is_string($first['value'])) {
            return false;
        }

        return preg_match('/^\\s*(total(es)?( general)?|subtotal(es)?)\\s*$/iu', $first['value']) === 1;
    }

    /**
     * @param  array<string, array<string, string>>  $formulaCells
     */
    private function assertFormulasCalculate(Spreadsheet $spreadsheet, array $formulaCells): void
    {
        foreach ($formulaCells as $sheetName => $cells) {
            $worksheet = $spreadsheet->getSheetByName($sheetName);

            if ($worksheet === null) {
                continue;
            }

            foreach ($cells as $coordinate => $expression) {
                try {
                    $value = $worksheet->getCell($coordinate)->getCalculatedValue();
                } catch (Throwable $exception) {
                    throw new AiToolRejected(
                        "La fórmula de la celda {$coordinate} de la hoja «{$sheetName}» no se pudo calcular: "
                        .$exception->getMessage()
                    );
                }

                if (is_string($value) && preg_match('/^#(NULL!|DIV\\/0!|VALUE!|REF!|NAME\\?|NUM!|N\\/A|SPILL!|CALC!)$/', $value) === 1) {
                    throw new AiToolRejected(
                        "La fórmula de la celda {$coordinate} de la hoja «{$sheetName}» produce {$value}. "
                        .'Corrige la fórmula o protégela con IFERROR.'
                    );
                }

                $format = $worksheet->getCell($coordinate)->getStyle()->getNumberFormat()->getFormatCode();

                if ($format === self::FORMAT_CODES['integer']) {
                    if (! is_numeric($value) || (float) $value !== floor((float) $value)) {
                        throw new AiToolRejected(
                            "La celda {$coordinate} de la hoja «{$sheetName}» tiene formato integer, "
                            .'pero su fórmula no produce un entero.'
                        );
                    }
                }
            }
        }
    }

    private function assertTextLength(string $text, string $sheet, int $row, int $column): void
    {
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new AiToolRejected(
                'La celda '.$this->coordinate($row, $column)." de la hoja «{$sheet}» supera los "
                .self::MAX_TEXT_LENGTH.' caracteres de Excel.'
            );
        }
    }

    private function safeText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return preg_match('/^[=+\-@\t\r]/u', $text) === 1 ? "'".$text : $text;
    }

    private function coordinate(int $row, int $column): string
    {
        return Coordinate::stringFromColumnIndex($column).$row;
    }

    private function looksLikeJson(string $trimmed): bool
    {
        return str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function rowsFromText(string $content): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || preg_match('/^\|?[\s:\-|]+\|?$/', $trimmed)) {
                continue;
            }

            if (str_contains($trimmed, '|')) {
                $cells = array_map('trim', explode('|', trim($trimmed, " \t|")));
            } else {
                $cells = str_getcsv($trimmed);
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }
}

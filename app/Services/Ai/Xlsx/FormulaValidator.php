<?php

declare(strict_types=1);

namespace App\Services\Ai\Xlsx;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Validates the small formula language the XLSX generator accepts.
 *
 * ## Why a parser instead of a blocklist
 *
 * A regex that looks for "HYPERLINK" or "WEBSERVICE" is bypassed by another
 * spelling, by a nested call, or by a character the pattern did not expect. The
 * generator therefore accepts only a bounded grammar: numbers, quoted strings,
 * cell and range references, arithmetic/comparison operators, parentheses and a
 * closed list of functions. Anything else is rejected before a cell is written.
 *
 * This is deliberately not a spreadsheet engine. It supports what the assistant
 * is told it may use — totals, discounts, cross-sheet references, COUNTIF and
 * guarded division — and refuses the rest, including external workbook
 * references, DDE, macros and functions that can reach the network or disk.
 */
final class FormulaValidator
{
    /** Functions the assistant may use. Value is [min args, max args]. */
    private const FUNCTIONS = [
        'SUM' => [1, 100],
        'AVERAGE' => [1, 100],
        'MIN' => [1, 100],
        'MAX' => [1, 100],
        'COUNT' => [1, 100],
        'COUNTA' => [1, 100],
        'COUNTIF' => [2, 2],
        'SUMIF' => [2, 3],
        'IF' => [3, 3],
        'IFERROR' => [2, 2],
        'ROUND' => [2, 2],
        'ABS' => [1, 1],
        'AND' => [1, 100],
        'OR' => [1, 100],
        'NOT' => [1, 1],
    ];

    /** Functions whose first argument must be a range. */
    private const RANGE_FIRST_ARG = ['COUNTIF', 'SUMIF'];

    /** Functions that accept a range anywhere in their arguments. */
    private const RANGE_ARGUMENTS = [
        'SUM', 'AVERAGE', 'MIN', 'MAX', 'COUNT', 'COUNTA', 'COUNTIF', 'SUMIF',
    ];

    /** @var list<array{type: string, value: string}> */
    private array $tokens = [];

    private int $position = 0;

    /** @var array<string, array{rows: int, cols: int}> */
    private array $bounds = [];

    /** @var array<string, string> lower-case name => canonical name */
    private array $sheetNames = [];

    private string $currentSheet = '';

    /**
     * Validate one expression and return it without the leading `=`.
     *
     * @param  array<string, array{rows: int, cols: int}>  $bounds
     * @param  list<string>  $sheetNames
     */
    public function validate(string $expression, string $currentSheet, array $bounds, array $sheetNames): string
    {
        $expression = trim($expression);

        if (! str_starts_with($expression, '=')) {
            throw new InvalidArgumentException('La fórmula debe empezar por "=".');
        }

        $formula = ltrim(substr($expression, 1));

        if ($formula === '') {
            throw new InvalidArgumentException('La fórmula está vacía.');
        }

        $this->currentSheet = $currentSheet;
        $this->bounds = [];
        $this->sheetNames = [];

        foreach ($sheetNames as $name) {
            $this->sheetNames[mb_strtolower($name)] = $name;
        }

        foreach ($bounds as $name => $bound) {
            $this->bounds[mb_strtolower($name)] = $bound;
        }

        $this->tokens = $this->tokenize($formula);
        $this->position = 0;

        $type = $this->parseComparison();

        if ($type === 'range') {
            throw new InvalidArgumentException('Una referencia de rango debe usarse dentro de una función como SUM o COUNTIF.');
        }

        if ($this->position !== count($this->tokens)) {
            throw new InvalidArgumentException('La fórmula contiene una construcción no admitida.');
        }

        return $formula;
    }

    /**
     * Detect the common "percentage multiplied by 100" mistake.
     *
     * A percentage cell stores a fraction (1/3), and Excel's percentage format
     * supplies the multiplication by 100 when displaying it. A formula that
     * already multiplies by 100 would display 3333.33% and is therefore rejected
     * structurally. `*100%` is not rejected: the percent operator makes it a
     * factor of one, not a scale by one hundred.
     */
    public function isScaledByHundred(string $expression): bool
    {
        $expression = trim($expression);

        if (str_starts_with($expression, '=')) {
            $expression = ltrim(substr($expression, 1));
        }

        $tokens = $this->tokenize($expression);
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token['type'] === 'operator' && $token['value'] === '*') {
                $next = $tokens[$index + 1] ?? null;
                $after = $tokens[$index + 2] ?? null;

                if ($next !== null && $next['type'] === 'number' && (float) $next['value'] === 100.0) {
                    if ($after === null || $after['type'] !== 'operator' || $after['value'] !== '%') {
                        return true;
                    }
                }

                if (
                    $next !== null && $next['type'] === 'number' && (float) $next['value'] === 10.0
                    && $after !== null && $after['type'] === 'operator' && $after['value'] === '^'
                    && isset($tokens[$index + 3])
                    && $tokens[$index + 3]['type'] === 'number'
                    && (float) $tokens[$index + 3]['value'] === 2.0
                ) {
                    return true;
                }
            }

            if ($token['type'] === 'operator' && $token['value'] === '/') {
                $next = $tokens[$index + 1] ?? null;

                if (
                    $next !== null && $next['type'] === 'number'
                    && abs((float) $next['value'] - 0.01) < 1e-12
                ) {
                    return true;
                }
            }

            if (
                $token['type'] === 'number' && (float) $token['value'] === 100.0
                && isset($tokens[$index + 1])
                && $tokens[$index + 1]['type'] === 'operator'
                && $tokens[$index + 1]['value'] === '*'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    private function tokenize(string $formula): array
    {
        $tokens = [];
        $length = strlen($formula);
        $index = 0;

        while ($index < $length) {
            $character = $formula[$index];

            if (ctype_space($character)) {
                $index++;

                continue;
            }

            $rest = substr($formula, $index);

            if ($character === '"') {
                [$value, $lengthOfString] = $this->readString($rest);
                $tokens[] = ['type' => 'string', 'value' => $value];
                $index += $lengthOfString;

                continue;
            }

            if ($character === "'") {
                [$value, $lengthOfString] = $this->readQuotedSheet($rest);
                $tokens[] = ['type' => 'sheet', 'value' => $value];
                $index += $lengthOfString;

                continue;
            }

            if (preg_match('/^\$?[A-Za-z]{1,3}\$?[0-9]+/', $rest, $match) === 1) {
                $tokens[] = ['type' => 'cell', 'value' => strtoupper($match[0])];
                $index += strlen($match[0]);

                continue;
            }

            if (preg_match('/^\p{L}[\p{L}\p{N}_.]*/u', $rest, $match) === 1) {
                $tokens[] = ['type' => 'ident', 'value' => $match[0]];
                $index += strlen($match[0]);

                continue;
            }

            if (preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)/', $rest, $match) === 1) {
                $tokens[] = ['type' => 'number', 'value' => $match[0]];
                $index += strlen($match[0]);

                continue;
            }

            foreach (['<=', '>=', '<>'] as $operator) {
                if (str_starts_with($rest, $operator)) {
                    $tokens[] = ['type' => 'operator', 'value' => $operator];
                    $index += 2;

                    continue 2;
                }
            }

            if (str_contains('+-*/^%(),:!<>=', $character)) {
                $tokens[] = ['type' => 'operator', 'value' => $character];
                $index++;

                continue;
            }

            throw new InvalidArgumentException('La fórmula contiene un carácter no admitido.');
        }

        return $tokens;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readString(string $rest): array
    {
        $value = '';
        $length = strlen($rest);

        for ($index = 1; $index < $length; $index++) {
            if ($rest[$index] === '"') {
                if ($index + 1 < $length && $rest[$index + 1] === '"') {
                    $value .= '"';
                    $index++;

                    continue;
                }

                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
                    throw new InvalidArgumentException('La fórmula contiene caracteres de control.');
                }

                return [$value, $index + 1];
            }

            $value .= $rest[$index];
        }

        throw new InvalidArgumentException('La fórmula tiene una cadena sin cerrar.');
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readQuotedSheet(string $rest): array
    {
        $value = '';
        $length = strlen($rest);

        for ($index = 1; $index < $length; $index++) {
            if ($rest[$index] === "'") {
                if ($index + 1 < $length && $rest[$index + 1] === "'") {
                    $value .= "'";
                    $index++;

                    continue;
                }

                return [$value, $index + 1];
            }

            $value .= $rest[$index];
        }

        throw new InvalidArgumentException('La fórmula tiene un nombre de hoja sin cerrar.');
    }

    private function parseComparison(): string
    {
        $type = $this->parseAdditive();

        while ($this->matchesOperator(['=', '<>', '<', '>', '<=', '>='])) {
            $this->assertScalar($type);
            $this->position++;
            $right = $this->parseAdditive();
            $this->assertScalar($right);
            $type = 'scalar';
        }

        return $type;
    }

    private function parseAdditive(): string
    {
        $type = $this->parseMultiplicative();

        while ($this->matchesOperator(['+', '-'])) {
            $this->assertScalar($type);
            $this->position++;
            $right = $this->parseMultiplicative();
            $this->assertScalar($right);
            $type = 'scalar';
        }

        return $type;
    }

    private function parseMultiplicative(): string
    {
        $type = $this->parsePower();

        while ($this->matchesOperator(['*', '/'])) {
            $this->assertScalar($type);
            $this->position++;
            $right = $this->parsePower();
            $this->assertScalar($right);
            $type = 'scalar';
        }

        return $type;
    }

    private function parsePower(): string
    {
        $type = $this->parseUnary();

        while ($this->matchesOperator(['^'])) {
            $this->assertScalar($type);
            $this->position++;
            $right = $this->parseUnary();
            $this->assertScalar($right);
            $type = 'scalar';
        }

        return $type;
    }

    private function parseUnary(): string
    {
        if ($this->matchesOperator(['+', '-'])) {
            $this->position++;
            $type = $this->parseUnary();
            $this->assertScalar($type);

            return 'scalar';
        }

        return $this->parsePostfix();
    }

    private function parsePostfix(): string
    {
        $type = $this->parsePrimary();

        while ($this->matchesOperator(['%'])) {
            $this->assertScalar($type);
            $this->position++;
        }

        return $type;
    }

    private function parsePrimary(): string
    {
        $token = $this->current();

        if ($token === null) {
            throw new InvalidArgumentException('La fórmula termina de forma incompleta.');
        }

        if ($token['type'] === 'number') {
            $this->position++;

            return 'scalar';
        }

        if ($token['type'] === 'string') {
            $this->position++;

            return 'scalar';
        }

        if ($token['type'] === 'sheet') {
            $this->position++;
            $this->expectOperator('!');

            return $this->parseReference($token['value'], true);
        }

        if ($token['type'] === 'ident') {
            $this->position++;

            if ($this->matchesOperator(['('])) {
                return $this->parseFunction($token['value']);
            }

            if ($this->matchesOperator(['!'])) {
                $this->position++;

                return $this->parseReference($token['value'], true);
            }

            throw new InvalidArgumentException('Los nombres definidos no están admitidos; usa referencias de celda.');
        }

        if ($token['type'] === 'cell') {
            $this->position++;

            if ($this->matchesOperator(['!'])) {
                // A sheet whose name happens to look like a cell reference,
                // e.g. A1!B2. It still must be a sheet that exists.
                $this->position++;

                return $this->parseReference($token['value'], true);
            }

            return $this->parseCellOrRange($this->currentSheet);
        }

        if ($token['type'] === 'operator' && $token['value'] === '(') {
            $this->position++;
            $type = $this->parseComparison();
            $this->expectOperator(')');

            return $type;
        }

        throw new InvalidArgumentException('La fórmula contiene una construcción no admitida.');
    }

    private function parseFunction(string $name): string
    {
        $upper = mb_strtoupper($name);

        if (! isset(self::FUNCTIONS[$upper])) {
            throw new InvalidArgumentException("La función {$upper} no está admitida.");
        }

        $this->expectOperator('(');
        $arguments = [];
        $argumentTypes = [];

        if (! $this->matchesOperator([')'])) {
            do {
                $type = $this->parseComparison();
                $arguments[] = $type;

                if ($this->matchesOperator([','])) {
                    $this->position++;

                    continue;
                }

                break;
            } while (true);
        }

        $this->expectOperator(')');

        [$minimum, $maximum] = self::FUNCTIONS[$upper];
        $count = count($arguments);

        if ($count < $minimum || $count > $maximum) {
            throw new InvalidArgumentException("La función {$upper} recibió un número de argumentos no válido.");
        }

        if ($upper === 'COUNTIF') {
            if ($arguments[0] !== 'range') {
                throw new InvalidArgumentException('COUNTIF necesita un rango como primer argumento.');
            }

            if ($arguments[1] !== 'scalar') {
                throw new InvalidArgumentException('COUNTIF necesita un criterio escalar como segundo argumento.');
            }
        }

        if ($upper === 'SUMIF' && $arguments[0] !== 'range') {
            throw new InvalidArgumentException('SUMIF necesita un rango como primer argumento.');
        }

        foreach ($arguments as $index => $type) {
            if ($type === 'range' && ! in_array($upper, self::RANGE_ARGUMENTS, true)) {
                throw new InvalidArgumentException("La función {$upper} no admite rangos como argumento.");
            }

            if ($type === 'range' && $upper === 'SUMIF' && $index > 1) {
                throw new InvalidArgumentException('SUMIF no admite rangos en el tercer argumento.');
            }
        }

        return 'scalar';
    }

    private function parseReference(string $sheetToken, bool $allowRange): string
    {
        $sheet = $this->canonicalSheet($sheetToken);
        $cells = [];

        $token = $this->current();

        if ($token === null || $token['type'] !== 'cell') {
            throw new InvalidArgumentException('La referencia entre hojas debe apuntar a una celda.');
        }

        $this->position++;
        $cells[] = $token['value'];

        if ($this->matchesOperator([':'])) {
            if (! $allowRange) {
                throw new InvalidArgumentException('El rango no está permitido en esta posición.');
            }

            $this->position++;
            $token = $this->current();

            if ($token === null || $token['type'] !== 'cell') {
                throw new InvalidArgumentException('El rango debe terminar en una celda.');
            }

            $this->position++;
            $cells[] = $token['value'];
        }

        foreach ($cells as $cell) {
            $this->assertCellInsideSheet($sheet, $cell);
        }

        return count($cells) === 2 ? 'range' : 'scalar';
    }

    private function parseCellOrRange(string $sheet): string
    {
        $first = $this->tokens[$this->position - 1]['value'] ?? '';

        // parsePrimary consumed the first cell. Recover it for validation and
        // look ahead for a range separator.
        $cells = [$first];

        if ($this->matchesOperator([':'])) {
            $this->position++;
            $token = $this->current();

            if ($token === null || $token['type'] !== 'cell') {
                throw new InvalidArgumentException('El rango debe terminar en una celda.');
            }

            $this->position++;
            $cells[] = $token['value'];
        }

        foreach ($cells as $cell) {
            $this->assertCellInsideSheet($sheet, $cell);
        }

        return count($cells) === 2 ? 'range' : 'scalar';
    }

    private function assertCellInsideSheet(string $sheet, string $cell): void
    {
        $canonical = $this->canonicalSheet($sheet);
        $key = mb_strtolower($canonical);

        if (! isset($this->bounds[$key])) {
            throw new InvalidArgumentException("La hoja «{$sheet}» no existe en el libro.");
        }

        $coordinate = str_replace('$', '', $cell);

        if (preg_match('/^([A-Z]{1,3})([0-9]+)$/', $coordinate, $match) !== 1) {
            throw new InvalidArgumentException("La referencia de celda «{$cell}» no es válida.");
        }

        $column = Coordinate::columnIndexFromString($match[1]);
        $row = (int) $match[2];

        if ($row < 1 || $column < 1 || $column > 16384 || $row > 1048576) {
            throw new InvalidArgumentException("La referencia de celda «{$cell}» está fuera de Excel.");
        }

        $bounds = $this->bounds[$key];

        if ($row > $bounds['rows'] || $column > $bounds['cols']) {
            throw new InvalidArgumentException(
                "La referencia «{$sheet}!{$cell}» está fuera de los datos de la hoja."
            );
        }
    }

    private function canonicalSheet(string $name): string
    {
        $key = mb_strtolower(trim($name));

        if (! isset($this->sheetNames[$key])) {
            throw new InvalidArgumentException("La hoja «{$name}» no existe en el libro.");
        }

        return $this->sheetNames[$key];
    }

    /**
     * @param  list<string>  $operators
     */
    private function matchesOperator(array $operators): bool
    {
        $token = $this->current();

        return $token !== null
            && $token['type'] === 'operator'
            && in_array($token['value'], $operators, true);
    }

    private function expectOperator(string $operator): void
    {
        if (! $this->matchesOperator([$operator])) {
            throw new InvalidArgumentException("La fórmula esperaba «{$operator}».");
        }

        $this->position++;
    }

    private function assertScalar(string $type): void
    {
        if ($type !== 'scalar') {
            throw new InvalidArgumentException('Un rango no puede usarse como operando suelto.');
        }
    }

    /**
     * @return array{type: string, value: string}|null
     */
    private function current(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }
}

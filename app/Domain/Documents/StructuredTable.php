<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * A tabular file, held as data instead of being restated by a model.
 *
 * ## The defect this exists to make impossible
 *
 * Asked to turn an attached `cartera.csv` into an Excel file, the assistant
 * produced rows with B's payment as `25000` where the file said `250000`, and a
 * totals row that summed to something the rows did not add up to. Nothing was
 * broken about the writer: the model had been asked to *repeat* the numbers in
 * its answer, and a language model repeating a table is exactly as reliable as
 * a person retyping it.
 *
 * So the numbers are never retyped. The file is parsed, the columns are
 * identified, the balance and the totals are computed in PHP with integers, and
 * what leaves is the computed table. A model may then *describe* the result but
 * it has no hand in producing it.
 *
 * ## Separators are read, not guessed at random
 *
 * `1.234.567,89` and `1,234,567.89` are the same amount written by two
 * countries, and a `.csv` with a `;` delimiter is still a `.csv`. The parser
 * decides which convention a cell is using from the cell itself, so `250000`
 * stays `250000` and never becomes `25000`.
 *
 * ## Source and computed values are kept distinct
 *
 * A computed balance is only computed when the table really has an obligation
 * column *and* a payment column. Where the file already carried a balance, the
 * computed one replaces it and a disagreement is recorded rather than hidden —
 * the person is told the file contradicted itself instead of being handed a
 * clean-looking total.
 */
final class StructuredTable
{
    /** Headers that name what is owed. */
    private const OBLIGATION_HEADERS = [
        'obligacion', 'importe', 'valor', 'monto', 'deuda', 'cuota', 'capital',
        'facturado', 'total facturado', 'total a pagar', 'valor obligacion', 'total',
    ];

    /** Headers that name what has been paid. */
    private const PAID_HEADERS = [
        'pagado', 'pago', 'pagos', 'abono', 'abonos', 'recibido', 'recaudado', 'aplicado', 'valor pagado',
    ];

    /** Headers that already name the remainder. */
    private const BALANCE_HEADERS = [
        'saldo pendiente', 'saldo', 'pendiente', 'por pagar', 'adeudado', 'restante', 'debe', 'saldo por pagar',
    ];

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly ?int $obligationColumn,
        public readonly ?int $paidColumn,
        public readonly ?int $balanceColumn,
    ) {}

    /**
     * Build from raw rows, where the first row is the header.
     *
     * @param  list<array<int, mixed>>  $rawRows
     */
    public static function fromRawRows(array $rawRows): self
    {
        $clean = [];

        foreach ($rawRows as $row) {
            if (! is_array($row)) {
                $row = [$row];
            }

            $values = array_map(static fn ($value): string => trim((string) $value), array_values($row));

            if (implode('', $values) !== '') {
                $clean[] = $values;
            }
        }

        if ($clean === []) {
            return new self([], [], null, null, null);
        }

        /** @var list<string> $headers */
        $headers = array_shift($clean);
        $width = count($headers);

        $rows = [];

        foreach ($clean as $row) {
            if (count($row) < $width) {
                $row = array_pad($row, $width, '');
            } elseif (count($row) > $width) {
                $row = array_slice($row, 0, $width);
            }

            $rows[] = array_values($row);
        }

        // Payments and balances are claimed before obligations, because a header
        // such as "total pagado" would otherwise match the obligation needle
        // "total" and be counted twice.
        $paid = self::findColumn($headers, self::PAID_HEADERS, $rows);
        $balance = self::findColumn($headers, self::BALANCE_HEADERS, $rows, array_values(array_filter([$paid])));
        $obligation = self::findColumn($headers, self::OBLIGATION_HEADERS, $rows, array_values(array_filter([$paid, $balance])));

        return new self($headers, $rows, $obligation, $paid, $balance);
    }

    /**
     * The first header that matches one of `$needles` and whose data is numeric.
     *
     * @param  list<string>  $headers
     * @param  list<string>  $needles
     * @param  list<list<string>>  $rows
     * @param  list<int>  $exclude
     */
    private static function findColumn(array $headers, array $needles, array $rows, array $exclude = []): ?int
    {
        foreach ($headers as $index => $header) {
            if (in_array($index, $exclude, true)) {
                continue;
            }

            $normalised = self::normaliseHeader($header);

            if ($normalised === '') {
                continue;
            }

            foreach ($needles as $needle) {
                if ($normalised === $needle || str_contains($normalised, $needle)) {
                    foreach ($rows as $row) {
                        if (self::parseNumber($row[$index] ?? '') !== null) {
                            return $index;
                        }
                    }

                    // Header matched but the column holds no numbers; try the
                    // next header rather than claiming a text column.
                    break;
                }
            }
        }

        return null;
    }

    /**
     * The table as it should be written, with the balance and totals computed.
     *
     * @return array{
     *     headers: list<string>,
     *     rows: list<list<string|int|float>>,
     *     totals: array<string, int|float>,
     *     inconsistencies: list<array{row: int, detail: string}>,
     *     computed_balance_column: ?int
     * }
     */
    public function computedSheet(bool $addBalance = true, bool $includeTotals = true): array
    {
        $headers = $this->headers;
        $obligation = $this->obligationColumn;
        $paid = $this->paidColumn;

        $balanceIndex = $this->balanceColumn;

        // A balance needs both an obligation and a payment to subtract; without
        // the pair, inventing one would be worse than leaving it out.
        $computeBalance = $addBalance && $obligation !== null && $paid !== null;

        if ($computeBalance && $balanceIndex === null) {
            $headers[] = 'Saldo pendiente (calculado)';
            $balanceIndex = count($headers) - 1;
        }

        $numericColumns = array_values(array_filter([$obligation, $paid, $balanceIndex], static fn ($i): bool => $i !== null));

        $inconsistencies = [];
        $rows = [];

        foreach ($this->rows as $position => $row) {
            $out = [];

            foreach ($row as $index => $value) {
                // Keep numbers real for the money columns, so a spreadsheet holds
                // a number and not the text "250000"; everything else stays text
                // so an identifier is not turned into a quantity.
                if (in_array($index, $numericColumns, true)) {
                    $number = self::parseNumber($value);
                    $out[$index] = $number ?? $value;

                    continue;
                }

                $out[$index] = $value;
            }

            if ($computeBalance) {
                $obligationValue = self::parseNumber($row[$obligation] ?? '') ?? 0;
                $paidValue = self::parseNumber($row[$paid] ?? '') ?? 0;
                $balance = self::numbersSubtract($obligationValue, $paidValue);

                if ($this->balanceColumn !== null) {
                    $declared = self::parseNumber($row[$this->balanceColumn] ?? '');

                    if ($declared !== null && ! self::numbersEqual($declared, $balance)) {
                        $inconsistencies[] = [
                            'row' => $position + 2,
                            'detail' => 'El saldo declarado ('.self::formatNumber($declared).') no coincide con '
                                .'la obligación menos los pagos ('.self::formatNumber($balance).').',
                        ];
                    }
                }

                $out[$balanceIndex] = $balance;
            }

            $rows[] = array_values($out);
        }

        $totals = [];

        if ($includeTotals) {
            foreach ([$obligation, $paid, $balanceIndex] as $index) {
                if ($index === null || ! isset($headers[$index])) {
                    continue;
                }

                $sum = 0;

                foreach ($rows as $row) {
                    $value = $row[$index] ?? null;

                    if (is_int($value) || is_float($value)) {
                        $sum += $value;
                    }
                }

                $totals[$headers[$index]] = $sum;
            }

            $totalRow = array_fill(0, count($headers), '');
            $totalRow[0] = 'TOTAL';

            foreach ($totals as $label => $sum) {
                $totalRow[array_search($label, $headers, true)] = $sum;
            }

            $rows[] = $totalRow;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'totals' => $totals,
            'inconsistencies' => $inconsistencies,
            'computed_balance_column' => $computeBalance ? $balanceIndex : null,
        ];
    }

    /**
     * Read a cell as a number, honouring either separator convention.
     *
     * `1.234.567,89` and `1,234,567.89` both read as 1234567.89; `250000` reads
     * as the integer 250000, so no digit is ever moved by a rounding shortcut.
     * A value that is not a number returns null and is left alone.
     */
    public static function parseNumber(mixed $raw): int|float|null
    {
        $text = trim((string) $raw);

        if ($text === '') {
            return null;
        }

        // Drop currency symbols, spaces (including non-breaking) and any other
        // decoration, keeping digits, separators and a sign.
        $text = preg_replace('/[^0-9.,\-]/u', '', $text) ?? '';

        if ($text === '' || preg_match('/^[-.,]+$/', $text)) {
            return null;
        }

        $hasDot = str_contains($text, '.');
        $hasComma = str_contains($text, ',');

        if ($hasDot && $hasComma) {
            // Whichever separator is last is the decimal one.
            if (strrpos($text, ',') > strrpos($text, '.')) {
                $text = str_replace('.', '', $text);
                $text = str_replace(',', '.', $text);
            } else {
                $text = str_replace(',', '', $text);
            }
        } elseif ($hasComma) {
            // A single comma followed by one or two digits is a decimal comma;
            // otherwise every comma is a thousands separator.
            if (substr_count($text, ',') === 1 && preg_match('/,\d{1,2}$/', $text)) {
                $text = str_replace(',', '.', $text);
            } else {
                $text = str_replace(',', '', $text);
            }
        } elseif ($hasDot) {
            // Groups of exactly three after every dot are thousands; anything
            // else is a decimal point.
            if (preg_match('/^\d{1,3}(\.\d{3})+$/', $text) || preg_match('/^\d{1,3}(\.\d{3})+\.\d+$/', $text)) {
                $text = str_replace('.', '', $text);
            }
        }

        if ($text === '' || $text === '-' || ! is_numeric($text)) {
            return null;
        }

        if (preg_match('/^-?\d+$/', $text)) {
            return (int) $text;
        }

        return (float) $text;
    }

    public static function normaliseHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = strtr($header, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        $header = preg_replace('/[^a-z0-9 ]+/u', ' ', $header) ?? $header;

        return trim(preg_replace('/\s+/', ' ', $header) ?? $header);
    }

    /**
     * Subtract without losing integer precision.
     */
    private static function numbersSubtract(int|float $a, int|float $b): int|float
    {
        if (is_int($a) && is_int($b)) {
            return $a - $b;
        }

        return round((float) $a - (float) $b, 2);
    }

    private static function numbersEqual(int|float $a, int|float $b): bool
    {
        return abs((float) $a - (float) $b) < 0.005;
    }

    public static function formatNumber(int|float $value): string
    {
        if (is_int($value) || $value === (float) (int) $value) {
            return number_format((float) $value, 0, ',', '.');
        }

        return number_format($value, 2, ',', '.');
    }
}

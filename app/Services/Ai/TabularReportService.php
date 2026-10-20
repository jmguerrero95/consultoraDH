<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Documents\StructuredTable;
use App\Models\AiAttachment;
use App\Models\User;
use App\Services\Ai\Tools\CreateArtifactTool;

/**
 * Builds a receivables workbook in PHP from an attached table.
 *
 * The model's only contribution is the compact plan in
 * {@see TabularReportPlan}. Every row, formula, total, indicator and number
 * format is produced here from the file's own values. No workbook JSON is asked
 * from a model, and no QA row is hardcoded: the number of rows and the last row
 * of every SUM/COUNTIF range come from the attachment.
 */
final class TabularReportService
{
    public function __construct(
        private readonly TabularAttachmentReader $reader,
        private readonly CreateArtifactTool $artifacts,
        private readonly AiTurnContext $turnContext,
    ) {}

    /**
     * @return array<string, mixed> the artifact result plus source/row metadata
     */
    public function generateForAttachment(
        AiAttachment $attachment,
        User $user,
        float $discountPercent,
        bool $includeSummary,
        string $filename,
        ?string $title = null,
        ?string $sheetName = null,
    ): array {
        if ($user->cannot('ai.use') || $user->cannot('artifacts.create')) {
            throw new AiToolRejected('Su rol no tiene permiso para generar este reporte.');
        }

        $conversationId = $this->turnContext->conversationId();

        if ($conversationId === null || ! $attachment->isAccessibleBy($user, $conversationId)) {
            throw new AiToolRejected('No tiene acceso a ese adjunto.');
        }

        $rawRows = $this->reader->rows($attachment, $sheetName);
        $spec = $this->buildSpec($rawRows, $discountPercent, $includeSummary);

        $filename = trim($filename) !== '' ? trim($filename) : 'Cartera_Resumen';
        $title = $title !== null && trim($title) !== '' ? trim($title) : $filename;

        $arguments = $this->artifacts->validate([
            'format' => 'xlsx',
            'filename' => $filename,
            'title' => $title,
            'content' => json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
        ]);

        $artifact = $this->artifacts->execute($arguments, $user);

        return [
            ...$artifact,
            'attachment_id' => (int) $attachment->id,
            'row_count' => count($spec['sheets'][0]['rows']) - 2, // header + totals row
            'report_type' => 'receivables',
        ];
    }

    /**
     * Build the XLSX v2 specification from raw file rows.
     *
     * @param  list<array<int, mixed>>  $rawRows
     * @return array<string, mixed>
     */
    public function buildSpec(array $rawRows, float $discountPercent, bool $includeSummary): array
    {
        $this->assertDiscount($discountPercent);
        $this->assertNoLostCells($rawRows);

        $table = StructuredTable::fromRawRows($rawRows);

        if ($table->headers === [] || $table->rows === []) {
            throw new AiToolRejected('La tabla adjunta no contiene filas de datos.');
        }

        $mapping = $table->receivablesMapping();

        if ($mapping['ambiguous'] !== []) {
            throw new AiToolRejected(
                'La tabla tiene columnas ambiguas para un reporte de cartera: '
                .implode(', ', $mapping['ambiguous']).'. Indica cuál corresponde.'
            );
        }

        if ($mapping['client'] === null) {
            throw new AiToolRejected('No se pudo identificar una columna de cliente en la tabla adjunta.');
        }

        if ($mapping['obligation'] === null) {
            throw new AiToolRejected('No se pudo identificar una columna de obligación en la tabla adjunta.');
        }

        if ($mapping['paid'] === null) {
            throw new AiToolRejected('No se pudo identificar una columna de pagos en la tabla adjunta.');
        }

        $clientIndex = $mapping['client'];
        $obligationIndex = $mapping['obligation'];
        $paidIndex = $mapping['paid'];

        // A declared balance that contradicts obligation - paid is an
        // inconsistency in the source, not a value to paper over with a clean
        // computed column.
        $check = $table->computedSheet(true, false);

        if ($check['inconsistencies'] !== []) {
            $first = $check['inconsistencies'][0];
            throw new AiToolRejected(
                'La tabla adjunta tiene un saldo inconsistente en la fila '
                .$first['row'].': '.$first['detail']
            );
        }

        $clients = [];

        foreach ($table->rows as $position => $row) {
            $rowNumber = $position + 2;
            $client = trim((string) ($row[$clientIndex] ?? ''));
            $obligation = StructuredTable::parseNumber($row[$obligationIndex] ?? '');
            $paid = StructuredTable::parseNumber($row[$paidIndex] ?? '');

            if ($client === '') {
                throw new AiToolRejected("La fila {$rowNumber} de la tabla no tiene cliente.");
            }

            // A totals row inside the data would be summed a second time. It is
            // not a client, and guessing that it is a summary is not safe.
            if (preg_match('/^(total(es)?|subtotal(es)?)$/iu', $client) === 1) {
                throw new AiToolRejected(
                    "La fila {$rowNumber} parece una fila de totales; elimínala del archivo de origen "
                    .'antes de generar el reporte.'
                );
            }

            if ($obligation === null) {
                throw new AiToolRejected("La fila {$rowNumber} no tiene una obligación numérica legible.");
            }

            if ($paid === null) {
                throw new AiToolRejected("La fila {$rowNumber} no tiene un pago numérico legible.");
            }

            if ($obligation < 0 || $paid < 0) {
                throw new AiToolRejected("La fila {$rowNumber} contiene un importe negativo.");
            }

            $clients[] = [
                'client' => $client,
                'obligation' => $obligation,
                'paid' => $paid,
            ];
        }

        if ($clients === []) {
            throw new AiToolRejected('La tabla adjunta no contiene clientes para el reporte.');
        }

        $lastDataRow = count($clients) + 1;
        $totalsRow = $lastDataRow + 1;
        $rate = $this->rateLiteral($discountPercent);

        $formula = static fn (string $expression): array => [
            'type' => 'formula',
            'expression' => $expression,
        ];

        $carteraRows = [[
            'Cliente',
            'Obligación',
            'Pagado',
            'Saldo pendiente',
            'Descuento',
            'Saldo ajustado',
        ]];

        foreach ($clients as $index => $client) {
            $row = $index + 2;
            $carteraRows[] = [
                $client['client'],
                $client['obligation'],
                $client['paid'],
                $formula("=B{$row}-C{$row}"),
                $formula("=D{$row}*{$rate}"),
                $formula("=D{$row}-E{$row}"),
            ];
        }

        $carteraRows[] = [
            'Totales',
            $formula("=SUM(B2:B{$lastDataRow})"),
            $formula("=SUM(C2:C{$lastDataRow})"),
            $formula("=SUM(D2:D{$lastDataRow})"),
            $formula("=SUM(E2:E{$lastDataRow})"),
            $formula("=SUM(F2:F{$lastDataRow})"),
        ];

        $book = [
            'version' => 2,
            'sheets' => [[
                'name' => 'Cartera',
                'rows' => $carteraRows,
                'column_formats' => [
                    'B' => 'currency_cop',
                    'C' => 'currency_cop',
                    'D' => 'currency_cop',
                    'E' => 'currency_cop',
                    'F' => 'currency_cop',
                ],
                'freeze_pane' => 'A2',
            ]],
        ];

        if (! $includeSummary) {
            return $book;
        }

        $summaryRows = [
            ['Indicador', 'Valor'],
            ['Obligaciones totales', $formula("=Cartera!B{$totalsRow}")],
            ['Pagado total', $formula("=Cartera!C{$totalsRow}")],
            ['Saldo pendiente total', $formula("=Cartera!D{$totalsRow}")],
            ['Descuento total', $formula("=Cartera!E{$totalsRow}")],
            ['Saldo ajustado total', $formula("=Cartera!F{$totalsRow}")],
            ['Clientes con deuda', $formula("=COUNTIF(Cartera!D2:D{$lastDataRow},\">0\")")],
            ['Porcentaje de recuperación', $formula("=IFERROR(Cartera!C{$totalsRow}/Cartera!B{$totalsRow},0)")],
        ];

        $book['sheets'][] = [
            'name' => 'Resumen',
            'rows' => $summaryRows,
            'column_formats' => ['B' => 'currency_cop'],
            'cell_formats' => [
                'B7' => 'integer',
                'B8' => 'percentage',
            ],
            'freeze_pane' => 'A2',
        ];

        $book['required_indicators'] = [
            'total_obligations',
            'total_paid',
            'total_balance',
            'total_discount',
            'total_adjusted',
            'debtor_count',
            'recovery_rate',
        ];

        $book['indicators'] = [
            ['id' => 'total_obligations', 'sheet' => 'Resumen', 'cell' => 'B2', 'kind' => 'formula', 'format' => 'currency_cop'],
            ['id' => 'total_paid', 'sheet' => 'Resumen', 'cell' => 'B3', 'kind' => 'formula', 'format' => 'currency_cop'],
            ['id' => 'total_balance', 'sheet' => 'Resumen', 'cell' => 'B4', 'kind' => 'formula', 'format' => 'currency_cop'],
            ['id' => 'total_discount', 'sheet' => 'Resumen', 'cell' => 'B5', 'kind' => 'formula', 'format' => 'currency_cop'],
            ['id' => 'total_adjusted', 'sheet' => 'Resumen', 'cell' => 'B6', 'kind' => 'formula', 'format' => 'currency_cop'],
            ['id' => 'debtor_count', 'sheet' => 'Resumen', 'cell' => 'B7', 'kind' => 'formula', 'format' => 'integer'],
            ['id' => 'recovery_rate', 'sheet' => 'Resumen', 'cell' => 'B8', 'kind' => 'formula', 'format' => 'percentage'],
        ];

        return $book;
    }

    private function assertDiscount(float $discountPercent): void
    {
        if ($discountPercent < 0 || $discountPercent > 100) {
            throw new AiToolRejected('El descuento debe estar entre 0 y 100 %.');
        }
    }

    /**
     * Reject a row that carries data beyond the header width.
     *
     * `StructuredTable` pads short rows and truncates long ones so the analyser
     * can be forgiving. A deliverable cannot silently drop a column of figures,
     * so the report path checks the raw row before that normalisation.
     *
     * @param  list<array<int, mixed>>  $rawRows
     */
    private function assertNoLostCells(array $rawRows): void
    {
        $header = null;
        $width = 0;

        foreach ($rawRows as $row) {
            $values = array_map(
                static fn ($value): string => trim((string) $value),
                array_values(is_array($row) ? $row : [$row]),
            );

            if (implode('', $values) === '') {
                continue;
            }

            if ($header === null) {
                $header = $values;
                $width = count($values);

                continue;
            }

            if (count($values) <= $width) {
                continue;
            }

            $extra = array_slice($values, $width);

            if (implode('', $extra) !== '') {
                throw new AiToolRejected(
                    'Una fila de la tabla tiene más columnas que el encabezado; '
                    .'no se puede generar el reporte sin perder información.'
                );
            }
        }

        if ($header === null || $width === 0) {
            throw new AiToolRejected('La tabla adjunta no tiene encabezados.');
        }
    }

    private function rateLiteral(float $discountPercent): string
    {
        $rate = number_format($discountPercent / 100, 6, '.', '');
        $rate = rtrim(rtrim($rate, '0'), '.');

        return $rate === '' ? '0' : $rate;
    }
}

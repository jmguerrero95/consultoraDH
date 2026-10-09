<?php

declare(strict_types=1);

namespace App\Domain\Reports\Export;

use App\Domain\Reports\ReportResult;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Renders a `ReportResult` as XLSX using the repository's existing OpenSpout dependency
 * (§81: reuse it, do not add a second spreadsheet library).
 *
 * Written to a temporary file and streamed from disk, because a generated report is
 * persisted privately and later downloaded through an authorised controller — it is never
 * serialised into JSON or held in memory.
 */
final class XlsxExporter
{
    public function writeTo(ReportResult $report, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $meta = $report->meta();

        $writer->addRow(Row::fromValues([
            'Consultora DH',
            $meta['title'] ?? '',
        ]));

        $writer->addRow(Row::fromValues([
            'Generado',
            $meta['generated_at'] ?? '',
            'Corte',
            $meta['as_of'] ?? '',
        ]));

        $filters = $meta['filters'] ?? [];
        if ($filters !== []) {
            $writer->addRow(Row::fromValues(['Filtros', implode(' | ', $filters)]));
        }

        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(array_map(
            static fn (array $column): string => $column['label'],
            $report->columns(),
        )));

        foreach ($report->rows() as $row) {
            $writer->addRow(Row::fromValues(array_map(
                static fn (array $column): mixed => FormulaGuard::cell($row[$column['key']] ?? ''),
                $report->columns(),
            )));
        }

        foreach ($report->totals() as $total) {
            $writer->addRow(Row::fromValues(array_map(
                static fn (array $column): string => (string) ($total[$column['key']] ?? ''),
                $report->columns(),
            )));
        }

        if (isset($meta['disclaimer'])) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues([$meta['disclaimer']]));
        }

        $writer->close();
    }
}

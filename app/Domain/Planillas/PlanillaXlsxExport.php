<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

use App\Models\ContributionSheet;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

final class PlanillaXlsxExport
{
    public function __construct(
        private readonly PlanillaExporter $exporter,
    ) {}

    /**
     * @return string The path to the generated file.
     */
    public function generate(ContributionSheet $sheet): string
    {
        $summary = $this->exporter->summary($sheet);

        $writer = new Writer;
        $tempPath = tempnam(sys_get_temp_dir(), 'planilla_').'.xlsx';
        $writer->openToFile($tempPath);

        $writer->addRow(Row::fromValues([
            'Consultora DH — Planilla '.$summary['operator_label'],
            $summary['company_name'],
            'Período: '.$summary['period_month'],
        ]));

        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues([
            'Referencia: '.$summary['reference'],
            'Número: '.$summary['sheet_number'],
            'Estado: '.$summary['status_label'],
        ]));

        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues([
            'Documento', 'Nombre', 'Cargo', 'EPS', 'AFP', 'ARL', 'CCF', 'Clase de riesgo',
            'Valor liquidado (COP)', 'Incluido', 'Motivo de exclusión',
        ]));

        foreach ($summary['lines'] as $line) {
            $writer->addRow(Row::fromValues([
                $line['document_type'].' '.$line['document_number'],
                $line['client_name'],
                $line['job_title'],
                $line['eps_name'],
                $line['afp_name'],
                $line['arl_name'],
                $line['ccf_name'],
                $line['arl_risk_class'],
                $line['liquidated_amount_cop'],
                $line['included'] ? 'Sí' : 'No',
                $line['exclusion_reason'],
            ]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([
            'Total liquidado (COP): '.$summary['total_liquidated_cop'],
            'Líneas incluidas: '.$summary['included_line_count'],
        ]));

        $writer->close();

        return $tempPath;
    }
}

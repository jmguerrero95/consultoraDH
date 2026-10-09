<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

use App\Models\ContributionSheet;
use Dompdf\Dompdf;
use Dompdf\Options;

final class PlanillaPdfExport
{
    public function __construct(
        private readonly PlanillaExporter $exporter,
    ) {}

    public function generate(ContributionSheet $sheet): string
    {
        $summary = $this->exporter->summary($sheet);

        $html = $this->renderHtml($summary);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $tempPath = tempnam(sys_get_temp_dir(), 'planilla_').'.pdf';
        file_put_contents($tempPath, $dompdf->output());

        return $tempPath;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function renderHtml(array $summary): string
    {
        $rows = '';
        foreach ($summary['lines'] as $line) {
            $rows .= '<tr>'
                .'<td>'.$line['document_type'].' '.$line['document_number'].'</td>'
                .'<td>'.$line['client_name'].'</td>'
                .'<td>'.$line['job_title'].'</td>'
                .'<td>'.$line['eps_name'].'</td>'
                .'<td>'.$line['afp_name'].'</td>'
                .'<td>'.$line['arl_name'].'</td>'
                .'<td>'.$line['ccf_name'].'</td>'
                .'<td>'.$line['arl_risk_class'].'</td>'
                .'<td style="text-align:right">'.number_format((int) $line['liquidated_amount_cop'], 0, ',', '.').'</td>'
                .'<td>'.($line['included'] ? 'Sí' : 'No').'</td>'
                .'</tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            .'body{font-family:Helvetica,sans-serif;font-size:11px;color:#333}'
            .'h1{font-size:16px;margin:0 0 4px}'
            .'h2{font-size:13px;margin:0 0 8px;color:#666}'
            .'table{width:100%;border-collapse:collapse;margin-top:12px}'
            .'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;font-size:10px}'
            .'th{background:#f5f5f5}'
            .'.footer{margin-top:16px;font-size:9px;color:#999}'
            .'</style></head><body>'
            .'<h1>Consultora DH</h1>'
            .'<h2>Planilla '.$summary['operator_label'].' — '.$summary['company_name'].' — Período '.$summary['period_month'].'</h2>'
            .'<p><strong>Referencia:</strong> '.$summary['reference'].' &nbsp; <strong>Número:</strong> '.$summary['sheet_number']
            .' &nbsp; <strong>Estado:</strong> '.$summary['status_label'].'</p>'
            .'<table><thead><tr>'
            .'<th>Documento</th><th>Nombre</th><th>Cargo</th><th>EPS</th><th>AFP</th><th>ARL</th><th>CCF</th><th>Riesgo</th><th>Valor (COP)</th><th>Incl.</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p><strong>Total liquidado:</strong> '.number_format((int) $summary['total_liquidated_cop'], 0, ',', '.').' COP'
            .' &nbsp; <strong>Líneas incluidas:</strong> '.$summary['included_line_count'].'</p>'
            .'<p class="footer">Documento interno de Consultora DH. No es un documento oficial de operador PILA.</p>'
            .'</body></html>';
    }
}

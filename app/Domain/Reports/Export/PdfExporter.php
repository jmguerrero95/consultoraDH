<?php

declare(strict_types=1);

namespace App\Domain\Reports\Export;

use App\Domain\Reports\ReportResult;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a `ReportResult` as a branded PDF.
 *
 * §54 requires the header to state Consultora DH, the report title, when it was generated,
 * the as-of date and the active filters, so a printed copy can be understood months later
 * without the screen that produced it.
 *
 * No remote fonts and no external browser service (§78): `isRemoteEnabled` is off, so a
 * report can never make the server fetch something while rendering it.
 */
final class PdfExporter
{
    public function writeTo(ReportResult $report, string $path): void
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($report));
        $dompdf->setPaper('A4', count($report->columns()) > 6 ? 'landscape' : 'portrait');
        $dompdf->render();

        file_put_contents($path, $dompdf->output());
    }

    private function html(ReportResult $report): string
    {
        $meta = $report->meta();

        $filters = '';
        foreach ($meta['filters'] ?? [] as $filter) {
            $filters .= '<li>'.e($filter).'</li>';
        }

        $head = '';
        foreach ($report->columns() as $column) {
            $head .= '<th>'.e($column['label']).'</th>';
        }

        $body = '';
        foreach ($report->rows() as $row) {
            $body .= '<tr>';
            foreach ($report->columns() as $column) {
                $value = $row[$column['key']] ?? '';
                $text = match ($column['type'] ?? 'text') {
                    'money' => is_numeric($value) ? number_format((float) $value, 0, ',', '.') : (string) $value,
                    default => (string) $value,
                };
                $body .= '<td'.(($column['type'] ?? '') === 'money' ? ' style="text-align:right"' : '').'>'.e($text).'</td>';
            }
            $body .= '</tr>';
        }

        $totals = '';
        foreach ($report->totals() as $total) {
            $totals .= '<tr class="total">';
            foreach ($report->columns() as $column) {
                $value = $total[$column['key']] ?? '';
                $text = is_numeric($value) ? number_format((float) $value, 0, ',', '.') : (string) $value;
                $totals .= '<td>'.e($text).'</td>';
            }
            $totals .= '</tr>';
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><style>'
            .'@page{margin:25px 20px;}'
            .'body{font-family:Helvetica,Arial,sans-serif;font-size:9px;color:#222;}'
            .'h1{font-size:15px;margin:0;color:#14304f;}'
            .'h2{font-size:12px;margin:2px 0 8px;font-weight:normal;color:#555;}'
            .'ul{font-size:8px;color:#555;margin:2px 0 10px;padding-left:14px;}'
            .'table{width:100%;border-collapse:collapse;margin-top:8px;}'
            .'th,td{border:1px solid #ccc;padding:3px 4px;text-align:left;vertical-align:top;}'
            .'th{background:#eef2f6;font-size:8px;}'
            .'tr.total td{font-weight:bold;background:#f7f7f7;}'
            .'.foot{margin-top:12px;font-size:7px;color:#888;}'
            .'</style></head><body>'
            .'<h1>Consultora DH</h1>'
            .'<h2>'.e((string) ($meta['title'] ?? 'Reporte')).'</h2>'
            .'<ul><li>Generado: '.e((string) ($meta['generated_at'] ?? '')).'</li>'
            .'<li>Corte: '.e((string) ($meta['as_of'] ?? '')).'</li>'
            .($filters === '' ? '' : '<li>Filtros:<ul>'.$filters.'</ul></li>')
            .'</ul>'
            .'<table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.$totals.'</tbody></table>'
            .'<p class="foot">'.e((string) ($meta['disclaimer'] ?? '')).'</p>'
            .'</body></html>';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

use App\Models\ContributionSheet;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The planilla as a spreadsheet.
 *
 * MEJ-007: like the reports, this was a correct file that was hard to read — one
 * default width for every column, a header row indistinguishable from the data, the
 * liquidated amount as a bare number and the total on a line of its own. The columns
 * now size themselves to their content, the header is a header, money carries a
 * thousands separator, and the total is highlighted. No value changed.
 */
final class PlanillaXlsxExport
{
    private const MIN_WIDTH = 10.0;

    private const MAX_WIDTH = 40.0;

    private const BRAND = '123A5E';

    private const BRAND_SUBTLE = 'E8F0F7';

    private const COLUMNS = [
        ['Documento', 18.0, 'text'],
        ['Nombre', 30.0, 'text'],
        ['Cargo', 18.0, 'text'],
        ['EPS', 22.0, 'text'],
        ['AFP', 22.0, 'text'],
        ['ARL', 22.0, 'text'],
        ['CCF', 22.0, 'text'],
        ['Clase de riesgo', 12.0, 'text'],
        ['Valor liquidado (COP)', 20.0, 'money'],
        ['Incluido', 10.0, 'text'],
        ['Motivo de exclusión', 34.0, 'text'],
    ];

    public function __construct(
        private readonly PlanillaExporter $exporter,
    ) {}

    /**
     * @return string The path to the generated file.
     */
    public function generate(ContributionSheet $sheet): string
    {
        $summary = $this->exporter->summary($sheet);

        $writer = new Writer(new Options(
            pageSetup: new PageSetup(
                pageOrientation: PageOrientation::LANDSCAPE,
                paperSize: PaperSize::A4,
                fitToWidth: 1,
            ),
        ));

        $tempPath = tempnam(sys_get_temp_dir(), 'planilla_').'.xlsx';
        $writer->openToFile($tempPath);

        $sheet_writer = $writer->getCurrentSheet();
        $sheet_writer->setName(mb_substr('Planilla '.$summary['operator_label'], 0, 31));

        // One width per column, from the widest thing it holds: a legal name and a
        // three-letter risk class do not deserve the same 8.43 characters.
        foreach (self::COLUMNS as $index => $column) {
            // OpenSpout's width API is 1-based (`min`/`max` go straight into the XML), while
            // this list is 0-based: passing `$index` wrote `min="0"` for the first column and
            // shifted every other one, which strict readers reject outright.
            $sheet_writer->setColumnWidth(min(self::MAX_WIDTH, max(self::MIN_WIDTH, $column[1])), $index + 1);
        }

        $writer->addRow(Row::fromValuesWithStyle(
            ['Consultora DH — Planilla '.$summary['operator_label']],
            new Style(fontBold: true, fontSize: 14),
        ));
        $writer->getOptions()->mergeCells(0, 1, count(self::COLUMNS) - 1, 1);

        $writer->addRow(Row::fromValues([
            $summary['company_name'],
            'NIT '.$summary['company_tax_id'],
            'Período: '.$summary['period_month'],
        ]));

        $writer->addRow(Row::fromValues([
            'Referencia: '.($summary['reference'] ?? '—'),
            'Número: '.($summary['sheet_number'] ?? '—'),
            'Estado: '.$summary['status_label'],
        ]));

        if ($summary['status'] === 'cancelled') {
            $writer->addRow(Row::fromValuesWithStyle(
                ['Cancelada: '.($summary['cancellation_reason'] ?? 'sin motivo registrado')],
                new Style(fontBold: true, fontColor: Color::DARK_RED),
            ));
        }

        $writer->addRow(Row::fromValues([]));

        $headerRow = $sheet_writer->getWrittenRowCount() + 1;

        $writer->addRow(Row::fromValuesWithStyle(
            array_map(static fn (array $column): string => (string) $column[0], self::COLUMNS),
            new Style(
                fontBold: true,
                fontColor: Color::WHITE,
                backgroundColor: self::BRAND,
                cellAlignment: CellAlignment::CENTER,
                shouldWrapText: true,
                border: new Border(
                    new BorderPart(BorderName::BOTTOM, self::BRAND, BorderWidth::THIN, BorderStyle::SOLID),
                ),
            ),
        ));

        $bodyStyles = array_map(
            static fn (array $column): Style => $column[2] === 'money'
                ? new Style(format: '#,##0')
                : new Style,
            self::COLUMNS,
        );

        foreach ($summary['lines'] as $line) {
            $writer->addRow(Row::fromValuesWithStyles([
                trim($line['document_type'].' '.$line['document_number']),
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
            ], $bodyStyles));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyle([
            'Total liquidado (COP)',
            $summary['total_liquidated_cop'],
            'Líneas incluidas',
            $summary['included_line_count'],
        ], new Style(
            fontBold: true,
            backgroundColor: self::BRAND_SUBTLE,
            border: new Border(
                new BorderPart(BorderName::TOP, self::BRAND, BorderWidth::THIN, BorderStyle::SOLID),
            ),
        )));

        $writer->getCurrentSheet()->setPrintTitleRows($headerRow.':'.$headerRow);

        $writer->close();

        return $tempPath;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Receivables\ReceivablesService;

/**
 * The factory that turns a report type plus a filter array into a `ReportResult`.
 *
 * §51 asks for one reporting layer rather than an ad-hoc query per export. Every screen,
 * PDF, CSV and XLSX in this milestone resolves its data through here, so "the same filter
 * contract" is a property of the code rather than a convention anybody has to remember.
 */
final class ReportFactory
{
    public function __construct(private readonly ReceivablesService $receivables) {}

    public function make(ReportType $type, array $filters): ReportResult
    {
        $validated = ReportFilter::validate($type, $filters);

        return match ($type) {
            ReportType::Morosos => PortfolioReport::outstanding($this->receivables, $validated),
            ReportType::Vencidos => PortfolioReport::overdue($this->receivables, $validated),
            ReportType::PorEmpresa => new CompanyPortfolioReport($validated),
            ReportType::PorEntidad => new EntityReport($validated),
            ReportType::Planillas => new PlanillaSummaryReport($validated),

            // FIX-EXPORT-IMPORT-03. Explicitly refused rather than left to `UnhandledMatchError`.
            //
            // The workbook has no flat representation: it is monthly sheets of company blocks with
            // colours, and a `columns()`/`rows()` shape would mean inventing a flat form and then
            // re-interpreting it — which is the thing the milestone forbids. It is still a
            // `ReportType`, so it reaches the generated-reports list, the audit trail and the
            // download authorisation; `ReportGenerator` routes it to its own writer instead.
            ReportType::LibroOperativo => throw new \LogicException(
                'El libro operativo no es un ReportResult: lo construye LegacyWorkbookBuilder.',
            ),
        };
    }

    /** Build from a raw request, rejecting an unknown type with a machine-readable code. */
    public function fromRaw(string $type, array $filters): ReportResult
    {
        return $this->make(ReportType::parse($type), $filters);
    }
}

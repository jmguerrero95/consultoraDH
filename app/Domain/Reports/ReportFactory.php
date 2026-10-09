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
        };
    }

    /** Build from a raw request, rejecting an unknown type with a machine-readable code. */
    public function fromRaw(string $type, array $filters): ReportResult
    {
        return $this->make(ReportType::parse($type), $filters);
    }
}

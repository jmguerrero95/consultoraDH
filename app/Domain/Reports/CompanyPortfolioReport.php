<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Receivables\ReceivablesService;
use App\Models\Company;

/**
 * R3 — portfolio/operational summary for one or more companies.
 *
 * Deliberately an **operational** summary: how many people a company has and how many
 * of them currently owe something. It is not a financial report and does not restate
 * A03's arithmetic — the money comes from `ReceivablesService`, the same derived balance
 * the Cartera screen reads.
 */
final class CompanyPortfolioReport implements ReportResult
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly array $filters,
        private readonly ReceivablesService $receivables,
    ) {}

    public function type(): ReportType
    {
        return ReportType::PorEmpresa;
    }

    public function filters(): array
    {
        return $this->filters;
    }

    public function meta(): array
    {
        return [
            'report_type' => $this->type()->value,
            'title' => $this->type()->label(),
            'as_of' => now()->toDateString(),
            'filters' => ReportFilter::describe($this->filters),
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'disclaimer' => 'Documento interno de Consultora DH.',
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'legal_name', 'label' => 'Empresa', 'type' => 'text'],
            ['key' => 'tax_id', 'label' => 'NIT', 'type' => 'text'],
            ['key' => 'active_clients', 'label' => 'Clientes activos', 'type' => 'integer'],
            ['key' => 'debtor_clients', 'label' => 'Clientes con saldo', 'type' => 'integer'],
            ['key' => 'outstanding_balance_cop', 'label' => 'Saldo pendiente (COP)', 'type' => 'money'],
        ];
    }

    public function rows(): array
    {
        $companies = Company::query()
            ->when(isset($this->filters['company_id']), fn ($query) => $query->whereKey((int) $this->filters['company_id']))
            ->orderBy('legal_name')
            ->get();

        // One grouped aggregate over A03's own derived table, rather than a sum per company
        // over the raw obligations table. The balance column is derived, so the raw table
        // has no such column to sum.
        $money = $this->receivables->outstandingByCompany();

        $rows = [];

        foreach ($companies as $company) {
            $figures = $money[$company->id] ?? null;

            $rows[] = [
                'legal_name' => $company->legal_name,
                'tax_id' => $company->tax_id,
                'active_clients' => (int) $company->assignments()
                    ->whereNull('ended_on')
                    ->distinct('client_id')
                    ->count('client_id'),
                'debtor_clients' => $figures['debtor_clients'] ?? 0,
                'outstanding_balance_cop' => $figures['outstanding_cop'] ?? 0,
            ];
        }

        return $rows;
    }

    public function totals(): array
    {
        $rows = $this->rows();

        return [[
            'legal_name' => 'TOTAL',
            'active_clients' => array_sum(array_column($rows, 'active_clients')),
            'debtor_clients' => array_sum(array_column($rows, 'debtor_clients')),
            'outstanding_balance_cop' => array_sum(array_column($rows, 'outstanding_balance_cop')),
        ]];
    }

    public function toArray(): array
    {
        return [
            'meta' => $this->meta(),
            'columns' => $this->columns(),
            'rows' => $this->rows(),
            'totals' => $this->totals(),
        ];
    }
}

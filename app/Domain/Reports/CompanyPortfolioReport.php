<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * R3 — portfolio/operational summary for one or more companies.
 *
 * Deliberately an **operational** summary: how many people a company has and how many
 * of them currently owe something. It is not a financial report and does not restate
 * A03's arithmetic — where money is involved it asks `ReceivablesService` rather than
 * summing `monthly_obligations` itself, because a per-company sum computed here would be
 * a third place where "how much does this company owe" is answered.
 */
final class CompanyPortfolioReport implements ReportResult
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(private readonly array $filters) {}

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
        $asOf = now()->toDateString();

        $companies = Company::query()
            ->when(isset($this->filters['company_id']), fn ($query) => $query->whereKey((int) $this->filters['company_id']))
            ->orderBy('legal_name')
            ->get();

        $rows = [];

        foreach ($companies as $company) {
            // Money comes from A03's own derived table, grouped by company. This is the
            // same `balance_cop` `ReceivablesService` reads, reached through one grouped
            // aggregate rather than a second formula written to match it.
            $money = DB::query()
                ->from('monthly_obligations')
                ->where('company_id', $company->id)
                ->where('balance_cop', '>', 0)
                ->selectRaw('coalesce(sum(balance_cop), 0) as outstanding')
                ->selectRaw('count(distinct client_id) as debtors')
                ->first();

            $rows[] = [
                'legal_name' => $company->legal_name,
                'tax_id' => $company->tax_id,
                'active_clients' => (int) $company->assignments()
                    ->whereNull('ended_on')
                    ->distinct('client_id')
                    ->count('client_id'),
                'debtor_clients' => (int) ($money->debtors ?? 0),
                'outstanding_balance_cop' => (int) ($money->outstanding ?? 0),
            ];
        }

        unset($asOf);

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

<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Models\ContributionSheet;

/**
 * R5 — operational summary of planillas.
 *
 * Exists so planillas are not an isolated module that nothing else can report on
 * (§52). It is new A05 data, so it queries A05's own tables and borrows no A03 arithmetic:
 * there is no money owed here, only what was filed and what it was paid.
 *
 * The total is derived from the persisted snapshot rather than stored, for the same
 * reason `ContributionSheet::totalLiquidatedCop()` is (§18).
 */
final class PlanillaSummaryReport implements ReportResult
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(private readonly array $filters) {}

    public function type(): ReportType
    {
        return ReportType::Planillas;
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
            'disclaimer' => 'Documento interno de Consultora DH. No es un documento oficial de operador PILA.',
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'period_month', 'label' => 'Periodo', 'type' => 'date'],
            ['key' => 'company_name', 'label' => 'Empresa', 'type' => 'text'],
            ['key' => 'operator', 'label' => 'Operador', 'type' => 'text'],
            ['key' => 'reference', 'label' => 'Referencia', 'type' => 'text'],
            ['key' => 'sheet_number', 'label' => 'Número de planilla', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Estado', 'type' => 'text'],
            ['key' => 'submitted_on', 'label' => 'Enviada', 'type' => 'date'],
            ['key' => 'paid_on', 'label' => 'Pagada', 'type' => 'date'],
            ['key' => 'included_lines', 'label' => 'Personas', 'type' => 'integer'],
            ['key' => 'total_liquidated_cop', 'label' => 'Total liquidado (COP)', 'type' => 'money'],
        ];
    }

    public function rows(): array
    {
        $sheets = ContributionSheet::query()
            ->with(['company', 'period'])
            ->when(isset($this->filters['period_id']), fn ($query) => $query->where('monthly_period_id', (int) $this->filters['period_id']))
            ->when(isset($this->filters['company_id']), fn ($query) => $query->where('company_id', (int) $this->filters['company_id']))
            ->when(isset($this->filters['operator']), fn ($query) => $query->where('operator', $this->filters['operator']))
            ->when(isset($this->filters['status']), fn ($query) => $query->where('status', $this->filters['status']))
            ->when(isset($this->filters['submitted_from']), fn ($query) => $query->where('submitted_on', '>=', $this->filters['submitted_from']))
            ->when(isset($this->filters['submitted_to']), fn ($query) => $query->where('submitted_on', '<=', $this->filters['submitted_to']))
            ->orderByDesc('created_at')
            ->get();

        return $sheets->map(fn (ContributionSheet $sheet): array => [
            'period_month' => $sheet->period?->period_month?->format('Y-m'),
            'company_name' => $sheet->company?->legal_name,
            'operator' => $sheet->operator->label(),
            'reference' => $sheet->reference,
            'sheet_number' => $sheet->sheet_number,
            'status' => $sheet->status->label(),
            'submitted_on' => $sheet->submitted_on?->format('Y-m-d'),
            'paid_on' => $sheet->paid_on?->format('Y-m-d'),
            'included_lines' => $sheet->includedLineCount(),
            'total_liquidated_cop' => $sheet->totalLiquidatedCop(),
        ])->all();
    }

    public function totals(): array
    {
        $rows = $this->rows();

        return [[
            'company_name' => 'TOTAL',
            'included_lines' => array_sum(array_column($rows, 'included_lines')),
            'total_liquidated_cop' => array_sum(array_column($rows, 'total_liquidated_cop')),
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

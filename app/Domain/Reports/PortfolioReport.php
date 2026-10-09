<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Receivables\ReceivablesService;
use Carbon\CarbonImmutable;

/**
 * R1 — Morosos / cartera pendiente, and R2 — Vencidos.
 *
 * ## §83: this delegates, it does not recalculate
 *
 * The single most important line in this milestone's reporting layer is the call to
 * `ReceivablesService::list()`. Outstanding balance, overdue balance, aging bucket and
 * traffic light are A03's arithmetic, and a report that recomputed them would be a second
 * receivables formula able to disagree with the cartera screen about how much a client
 * owes. §83 says stop and delegate when you feel yourself copying the calculation.
 *
 * The only things added here are interpretation of the filters and the shape of the
 * output. "Due today is not overdue" stays A03's rule, because A03 compares `due_on`
 * against the as-of date, not against `now()`.
 */
final class PortfolioReport implements ReportResult
{
    /**
     * @param  array<string, mixed>  $filters
     */
    private function __construct(
        private readonly ReceivablesService $receivables,
        private readonly ReportType $type,
        private readonly array $filters,
    ) {}

    /** R2: only what is already late. */
    public static function overdue(ReceivablesService $receivables, array $filters): self
    {
        return new self($receivables, ReportType::Vencidos, $filters + ['overdue_only' => true]);
    }

    /** R1: everything still owed. */
    public static function outstanding(ReceivablesService $receivables, array $filters): self
    {
        return new self($receivables, ReportType::Morosos, $filters);
    }

    public function type(): ReportType
    {
        return $this->type;
    }

    public function filters(): array
    {
        return $this->filters;
    }

    public function meta(): array
    {
        return [
            'report_type' => $this->type->value,
            'title' => $this->type->label(),
            'as_of' => $this->asOf() ?? now()->toDateString(),
            'filters' => ReportFilter::describe($this->filters),
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'disclaimer' => 'Documento interno de Consultora DH.',
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'full_name', 'label' => 'Cliente', 'type' => 'text'],
            ['key' => 'document_label', 'label' => 'Documento', 'type' => 'text'],
            ['key' => 'company_names', 'label' => 'Empresas', 'type' => 'text'],
            ['key' => 'balance_cop', 'label' => 'Saldo pendiente (COP)', 'type' => 'money'],
            ['key' => 'overdue_balance_cop', 'label' => 'Saldo vencido (COP)', 'type' => 'money'],
            ['key' => 'overdue_periods_count', 'label' => 'Periodos vencidos', 'type' => 'integer'],
            ['key' => 'aging_bucket', 'label' => 'Antigüedad', 'type' => 'text'],
            ['key' => 'traffic_light', 'label' => 'Semáforo', 'type' => 'text'],
        ];
    }

    public function rows(): array
    {
        // A03's own arithmetic and A03's own filters. The names below are A03's, not this
        // class's invention: `minimum_balance`, `overdue`, `aging_bucket`, `traffic_light`.
        $result = $this->receivables->list(
            array_filter([
                'as_of' => $this->asOf(),
                'company_id' => $this->filters['company_id'] ?? null,
                'search' => $this->filters['search'] ?? null,
                'aging_bucket' => $this->filters['aging_bucket'] ?? null,
                'traffic_light' => $this->filters['traffic_light'] ?? null,
                'minimum_balance' => $this->filters['minimum_balance'] ?? null,
                'overdue' => ($this->filters['overdue_only'] ?? false) ? true : null,
            ], static fn ($value): bool => $value !== null),
            1,
            500,
        );

        $rows = [];

        foreach ($result['items'] as $item) {
            $rows[] = [
                'full_name' => $item['full_name'],
                'document_label' => $item['document_label'],
                'company_names' => implode(', ', $item['company_names'] ?? []),
                'balance_cop' => (int) $item['balance_cop'],
                'overdue_balance_cop' => (int) $item['overdue_balance_cop'],
                'overdue_periods_count' => (int) $item['overdue_periods_count'],
                'aging_bucket' => $item['aging_bucket'],
                'traffic_light' => $item['traffic_light_label'],
            ];
        }

        return $rows;
    }

    public function totals(): array
    {
        $rows = $this->rows();

        return [[
            'full_name' => 'TOTAL',
            'document_label' => (string) count($rows).' cliente(s)',
            'balance_cop' => array_sum(array_column($rows, 'balance_cop')),
            'overdue_balance_cop' => array_sum(array_column($rows, 'overdue_balance_cop')),
        ]];
    }

    public function toArray(): array
    {
        return [
            'meta' => $this->meta(),
            'columns' => $this->columns(),
            'rows' => $this->rows(),
            'totals' => $this->totals(),
            'summary' => $this->receivables->list(
                array_filter([
                    'as_of' => $this->asOf(),
                    'company_id' => $this->filters['company_id'] ?? null,
                    'search' => $this->filters['search'] ?? null,
                    'overdue' => ($this->filters['overdue_only'] ?? false) ? true : null,
                ], static fn ($value): bool => $value !== null),
                1,
                1,
            )['summary'],
        ];
    }

    private function asOf(): ?string
    {
        if (! isset($this->filters['as_of'])) {
            return null;
        }

        return CarbonImmutable::parse((string) $this->filters['as_of'])->toDateString();
    }
}

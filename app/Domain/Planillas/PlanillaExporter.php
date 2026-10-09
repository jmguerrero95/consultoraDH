<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

use App\Models\ContributionSheet;
use App\Models\ContributionSheetLine;

final class PlanillaExporter
{
    public function __construct(
        private readonly PlanillaFileStore $files,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(ContributionSheet $sheet): array
    {
        $sheet->load(['lines', 'files', 'company', 'period']);

        return [
            'id' => (int) $sheet->id,
            'company_name' => $sheet->company->legal_name,
            'company_tax_id' => $sheet->company->tax_id,
            'period_month' => $sheet->period->period_month->format('Y-m-d'),
            'operator' => $sheet->operator->value,
            'operator_label' => $sheet->operator->label(),
            'operator_other_name' => $sheet->operator_other_name,
            'status' => $sheet->status->value,
            'status_label' => $sheet->status->label(),
            'reference' => $sheet->reference,
            'sheet_number' => $sheet->sheet_number,
            'submitted_on' => $sheet->submitted_on?->format('Y-m-d'),
            'paid_on' => $sheet->paid_on?->format('Y-m-d'),
            'total_liquidated_cop' => $sheet->totalLiquidatedCop(),
            'included_line_count' => $sheet->includedLineCount(),
            'line_count' => $sheet->lines->count(),
            'notes' => $sheet->notes,
            'source_digest' => $sheet->source_digest,
            'revision' => (int) $sheet->revision,
            'files' => $sheet->files->map(fn ($file): array => [
                'id' => (int) $file->id,
                'kind' => $file->kind,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size_bytes' => (int) $file->size_bytes,
                'created_at' => $file->created_at->toIso8601String(),
            ])->all(),
            'lines' => $sheet->lines->map(fn (ContributionSheetLine $line): array => [
                'id' => (int) $line->id,
                'client_name' => $line->client_name,
                'document_number' => $line->document_number,
                'document_type' => $line->document_type,
                'company_name' => $line->company_name,
                'job_title' => $line->job_title,
                'eps_name' => $line->eps_name,
                'afp_name' => $line->afp_name,
                'arl_name' => $line->arl_name,
                'ccf_name' => $line->ccf_name,
                'arl_risk_class' => $line->arl_risk_class,
                'liquidated_amount_cop' => $line->liquidated_amount_cop,
                'included' => (bool) $line->included,
                'exclusion_reason' => $line->exclusion_reason,
            ])->all(),
        ];
    }

    /**
     * @param  list<ContributionSheet>  $sheets
     * @return array<string, mixed>
     */
    public function listSummary(array $sheets): array
    {
        return [
            'data' => array_map(fn (ContributionSheet $sheet): array => [
                'id' => (int) $sheet->id,
                'company_name' => $sheet->company?->legal_name,
                'period_month' => $sheet->period?->period_month?->format('Y-m-d'),
                'operator' => $sheet->operator?->value,
                'operator_label' => $sheet->operator?->label(),
                'status' => $sheet->status?->value,
                'status_label' => $sheet->status?->label(),
                'reference' => $sheet->reference,
                'sheet_number' => $sheet->sheet_number,
                'submitted_on' => $sheet->submitted_on?->format('Y-m-d'),
                'paid_on' => $sheet->paid_on?->format('Y-m-d'),
                'total_liquidated_cop' => $sheet->totalLiquidatedCop(),
                'included_line_count' => $sheet->includedLineCount(),
            ], $sheets),
        ];
    }
}

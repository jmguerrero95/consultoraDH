<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ContributionSheetLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's snapshot inside a planilla.
 *
 * Every human-readable column here is a **copy taken once**, not a live read of a master. §9.2
 * forbids rewriting history as a shortcut, and a planilla is history: correcting a person's name or
 * moving a job title must not silently change what a past month said.
 *
 * The `client_id` / `client_company_assignment_id` pair stays so the copy can be traced back.
 */
class ContributionSheetLine extends Model
{
    /** @use HasFactory<ContributionSheetLineFactory> */
    use HasFactory;

    protected $fillable = [
        'contribution_sheet_id',
        'client_id',
        'client_company_assignment_id',
        'document_type',
        'document_number',
        'client_name',
        'company_tax_id',
        'company_name',
        'relationship_started_on',
        'relationship_started_on_precision',
        'relationship_ended_on',
        'relationship_ended_on_precision',
        'eps_name',
        'afp_name',
        'arl_name',
        'ccf_name',
        'arl_risk_class',
        'job_title',
        'liquidated_amount_cop',
        'included',
        'exclusion_reason',
        'source_evidence',
    ];

    protected function casts(): array
    {
        return [
            'relationship_started_on' => 'date',
            'relationship_ended_on' => 'date',
            'liquidated_amount_cop' => 'integer',
            'included' => 'boolean',
            'source_evidence' => 'array',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(ContributionSheet::class, 'contribution_sheet_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ClientCompanyAssignment::class, 'client_company_assignment_id');
    }
}

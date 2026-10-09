<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\PlanillaOperator;
use Database\Factories\ContributionSheetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monthly operational contribution sheet.
 *
 * @property string $operator
 * @property ContributionSheetStatus $status
 */
class ContributionSheet extends Model
{
    /** @use HasFactory<ContributionSheetFactory> */
    use HasFactory;

    protected $fillable = [
        'monthly_period_id',
        'company_id',
        'operator',
        'operator_other_name',
        'sheet_number',
        'reference',
        'status',
        'submitted_on',
        'paid_on',
        'notes',
        'created_by',
        'source_digest',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'operator' => PlanillaOperator::class,
            'status' => ContributionSheetStatus::class,
            'submitted_on' => 'date',
            'paid_on' => 'date',
            'revision' => 'integer',
            'source_digest' => 'string',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(MonthlyPeriod::class, 'monthly_period_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ContributionSheetLine::class, 'contribution_sheet_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ContributionSheetFile::class, 'contribution_sheet_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * §18: the total is derived, never stored.
     *
     * A persisted "balance" on a sheet would be a second source of truth that could disagree with its
     * own lines, which is exactly the class of bug §9.4 forbids elsewhere.
     */
    public function totalLiquidatedCop(): int
    {
        return (int) $this->lines()
            ->where('included', true)
            ->whereNotNull('liquidated_amount_cop')
            ->sum('liquidated_amount_cop');
    }

    public function includedLineCount(): int
    {
        return (int) $this->lines()->where('included', true)->count();
    }

    public function hasPaymentProof(): bool
    {
        return $this->files()->where('kind', 'payment_receipt')->exists();
    }
}

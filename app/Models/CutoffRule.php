<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\CutoffMonthOffset;
use App\Domain\Billing\CutoffScope;
use App\Domain\Periods\MonthlyPeriod as MonthValue;
use Database\Factories\CutoffRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * When a monthly contribution is due, for some scope.
 *
 * The row is historical: `effective_month` is the first month it applies to, and a
 * later row for the same scope supersedes it rather than editing it. Changing a
 * cutoff for October means creating a new rule, which leaves the rule that
 * produced October's due dates intact and still readable.
 *
 * A rule never stores a resolved date. It stores a day and an offset, and the
 * resolver turns those into a date for a specific month, clamping to the last day
 * of a short month. Storing a date here would freeze the rule to the month it was
 * created in, which is not the same thing.
 *
 * @property int $id
 * @property CutoffScope $scope
 * @property int|null $company_id
 * @property int|null $client_id
 * @property Carbon $effective_month
 * @property int $cutoff_day
 * @property CutoffMonthOffset $month_offset
 */
#[Fillable([
    'scope',
    'company_id',
    'client_id',
    'effective_month',
    'cutoff_day',
    'month_offset',
    'notes',
    'created_by',
])]
class CutoffRule extends Model
{
    /** @use HasFactory<CutoffRuleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => CutoffScope::class,
            'effective_month' => 'date',
            'cutoff_day' => 'integer',
            'month_offset' => CutoffMonthOffset::class,
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function month(): MonthValue
    {
        return MonthValue::fromFirstDay($this->effective_month);
    }

    /**
     * Rules of one scope that had already started by the given month.
     *
     * The newest applicable row is the answer, so the ordering is part of the
     * contract rather than a presentation detail.
     *
     * @param  Builder<CutoffRule>  $query
     */
    public function scopeApplicableTo(Builder $query, MonthValue $month): void
    {
        $query->where('effective_month', '<=', $month->startsOn())
            ->orderByDesc('effective_month')
            ->orderByDesc('id');
    }

    /**
     * An example of what this rule produces, for the configuration screen.
     *
     * Purely for display: what the operator sees here is what the resolver will
     * produce for that month, and it is computed by the same clamping code so the
     * screen cannot show a date the system would not generate.
     */
    public function resolveFor(MonthValue $month): Carbon
    {
        $target = $month->startsOn();

        if ($this->month_offset === CutoffMonthOffset::FollowingMonth) {
            $target = $target->copy()->addMonthNoOverflow();
        }

        $target->day = min($this->cutoff_day, $target->daysInMonth);

        return $target->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditMetadata(): array
    {
        return [
            'scope' => $this->scope->value,
            'company_id' => $this->company_id,
            'client_id' => $this->client_id,
            'effective_month' => $this->month()->key(),
            'cutoff_day' => $this->cutoff_day,
            'month_offset' => $this->month_offset->value,
        ];
    }
}

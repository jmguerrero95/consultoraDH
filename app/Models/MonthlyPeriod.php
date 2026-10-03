<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Domain\Periods\PeriodStatus;
use Database\Factories\MonthlyPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One month that obligations are generated into.
 *
 * The row is the decision "we bill this month", not a calendar artefact. It exists
 * because somebody created it, and its state changes only through the explicit
 * actions in `App\Domain\Periods\Actions`.
 *
 * @property int $id
 * @property Carbon $period_month
 * @property PeriodStatus $status
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $reopened_at
 * @property string|null $last_reopen_reason
 */
#[Fillable([
    'period_month',
    'status',
    'opened_at',
    'opened_by',
    'closed_at',
    'closed_by',
    'reopened_at',
    'reopened_by',
    'last_reopen_reason',
    'generation_performed_at',
    'generation_performed_by',
])]
class MonthlyPeriod extends Model
{
    /** @use HasFactory<MonthlyPeriodFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'status' => PeriodStatus::class,
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'generation_performed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<MonthlyObligation, $this>
     */
    public function obligations(): HasMany
    {
        return $this->hasMany(MonthlyObligation::class, 'period_id');
    }

    /**
     * @return HasMany<AuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class, 'subject_id')
            ->where('subject_type', self::class);
    }

    public function isOpen(): bool
    {
        return $this->status === PeriodStatus::Open;
    }

    public function isClosed(): bool
    {
        return $this->status === PeriodStatus::Closed;
    }

    /** The month this row is, as a value object, so callers never do date arithmetic. */
    public function month(): MonthValue
    {
        return MonthValue::fromFirstDay($this->period_month);
    }

    public function key(): string
    {
        return $this->month()->key();
    }

    public function label(): string
    {
        return $this->month()->label();
    }

    /**
     * Only open periods.
     *
     * @param  Builder<MonthlyPeriod>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', PeriodStatus::Open->value);
    }

    /**
     * Only closed periods.
     *
     * @param  Builder<MonthlyPeriod>  $query
     */
    #[Scope]
    protected function closed(Builder $query): void
    {
        $query->where('status', PeriodStatus::Closed->value);
    }

    /**
     * Periods up to and including the given month, newest first.
     *
     * @param  Builder<MonthlyPeriod>  $query
     */
    #[Scope]
    protected function upToMonth(Builder $query, MonthValue $month): void
    {
        $query->where('period_month', '<=', $month->startsOn());
    }
}

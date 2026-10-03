<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Periods\MonthlyPeriod as MonthValue;
use Database\Factories\ClientCompanyRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one client owes one company, from a given month onwards.
 *
 * The amount is configured, not derived. There is no rule here that works out a
 * figure from EPS, AFP, ARL, CCF, a risk level, a salary or a minimum wage,
 * because no such rule was supplied and inventing one would produce numbers that
 * look authoritative and are not. Whoever sets these values is the business; this
 * table records what they said and from when.
 *
 * History is a sequence of decisions: one row per client, per company, per
 * effective month, and the newest row that has started is the answer for any given
 * month. Overlapping intervals are therefore impossible and need no interval
 * arithmetic to resolve.
 *
 * Once an obligation quotes a rate, that rate is evidence. Changing its amount
 * would change what the system said it had billed, which is exactly the silent
 * recalculation this module exists to prevent; `RateInUse` refuses it and the way
 * to change a value is a new row with a later effective month.
 *
 * @property int $id
 * @property int $client_id
 * @property int $company_id
 * @property Carbon $effective_month
 * @property int $amount_cop
 */
#[Fillable([
    'client_id',
    'company_id',
    'effective_month',
    'amount_cop',
    'notes',
    'created_by',
])]
class ClientCompanyRate extends Model
{
    /** @use HasFactory<ClientCompanyRateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_month' => 'date',
            'amount_cop' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function month(): MonthValue
    {
        return MonthValue::fromFirstDay($this->effective_month);
    }

    /**
     * The newest row for this pair that had started by the given month.
     *
     * @param  Builder<ClientCompanyRate>  $query
     */
    public function scopeForMonth(Builder $query, MonthValue $month): void
    {
        $query->where('effective_month', '<=', $month->startsOn())
            ->orderByDesc('effective_month')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditMetadata(): array
    {
        return [
            'client_id' => $this->client_id,
            'company_id' => $this->company_id,
            'effective_month' => $this->month()->key(),
            'amount_cop' => $this->amount_cop,
        ];
    }
}

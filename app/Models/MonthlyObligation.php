<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\ObligationSource;
use App\Domain\Periods\MonthlyPeriod as MonthValue;
use Database\Factories\MonthlyObligationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What one client owed one company for one month, as generated.
 *
 * ## The snapshot
 *
 * `base_amount_cop`, `due_on`, `rate_id` and `cutoff_rule_id` are what the system
 * decided at generation time. Nothing about them is recomputed. If the rate for
 * this client is corrected in November, March's obligation keeps saying what
 * March was billed, because an obligation that tracked its inputs would make
 * "what were we billed?" answerable only for the last month somebody edited.
 *
 * Corrections are `ObligationAdjustment` rows: separate, signed, reason-carrying
 * entries in a ledger that can be read and reversed. That is the difference between
 * correcting a mistake and rewriting history.
 *
 * ## Identity is referenced
 *
 * `client_id` and `company_id` are foreign keys and no name is copied. A02's
 * historical relationships already record who was associated with whom and when;
 * two copies of the same fact would drift apart. What this table adds is the money
 * and the dates, which nothing else holds.
 *
 * ## No status column
 *
 * There is deliberately no `status`. Paid, partial and pending are all derived from
 * the balance, and a stored status would be a second answer to a question that can
 * be computed exactly. `SettlementState` derives it; see `ObligationTotals`.
 *
 * @property int $id
 * @property int $period_id
 * @property int $client_id
 * @property int $company_id
 * @property int|null $client_company_assignment_id
 * @property int|null $rate_id
 * @property int|null $cutoff_rule_id
 * @property int $base_amount_cop
 * @property Carbon $due_on
 * @property Carbon $generated_at
 * @property ObligationSource $source
 */
#[Fillable([
    'period_id',
    'client_id',
    'company_id',
    'client_company_assignment_id',
    'rate_id',
    'cutoff_rule_id',
    'base_amount_cop',
    'due_on',
    'generated_at',
    'generated_by',
    'source',
])]
class MonthlyObligation extends Model
{
    /** @use HasFactory<MonthlyObligationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_amount_cop' => 'integer',
            'due_on' => 'date',
            'generated_at' => 'immutable_datetime',
            'source' => ObligationSource::class,
        ];
    }

    /**
     * @return BelongsTo<MonthlyPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(MonthlyPeriod::class, 'period_id');
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
     * @return BelongsTo<ClientCompanyAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ClientCompanyAssignment::class, 'client_company_assignment_id');
    }

    /**
     * @return BelongsTo<ClientCompanyRate, $this>
     */
    public function rate(): BelongsTo
    {
        return $this->belongsTo(ClientCompanyRate::class, 'rate_id');
    }

    /**
     * @return BelongsTo<CutoffRule, $this>
     */
    public function cutoffRule(): BelongsTo
    {
        return $this->belongsTo(CutoffRule::class, 'cutoff_rule_id');
    }

    /**
     * @return HasMany<ObligationAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(ObligationAdjustment::class, 'obligation_id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'obligation_id');
    }

    /**
     * What is still owed on this obligation.
     *
     * The same formula as `ObligationTotals`, reached from the model so a test or a caller
     * can ask without assembling two aggregates by hand:
     *
     *     base + every adjustment − live allocations from payments that are not voided
     *
     * Derived, never stored, exactly like the rest of the money in this module. A voided
     * payment's allocations stay in the database but stop counting, which is what makes a
     * void take effect everywhere at once.
     */
    public function balance(): int
    {
        $adjustments = (int) $this->adjustments()->sum('delta_cop');
        $paid = (int) PaymentAllocation::query()
            ->where('obligation_id', $this->id)
            ->whereNull('reversed_at')
            ->whereHas('payment', fn ($query) => $query->whereNull('voided_at'))
            ->sum('amount_cop');

        return $this->base_amount_cop + $adjustments - $paid;
    }

    public function month(): MonthValue
    {
        return MonthValue::fromFirstDay($this->period->period_month);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditMetadata(): array
    {
        return [
            'period_id' => $this->period_id,
            'client_id' => $this->client_id,
            'company_id' => $this->company_id,
            'base_amount_cop' => $this->base_amount_cop,
            'due_on' => $this->due_on?->format('Y-m-d'),
            'rate_id' => $this->rate_id,
            'cutoff_rule_id' => $this->cutoff_rule_id,
        ];
    }
}

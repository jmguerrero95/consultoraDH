<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What part of a payment pays what part of an obligation.
 *
 * Append-only, like the adjustment ledger. A wrong allocation is reversed with a
 * marker and a reason, and a new one written; the original row is never edited and
 * never deleted, so "what did somebody believe this payment was for, and when" is
 * answerable after the fact.
 *
 * The database refuses two live allocations of the same payment to the same
 * obligation, so this is either one allocation or two by construction rather than
 * by a sum somebody has to interpret. A reversal frees the pair.
 *
 * @property int $id
 * @property int $payment_id
 * @property int $obligation_id
 * @property int $amount_cop
 * @property Carbon|null $reversed_at
 */
#[Fillable([
    'payment_id',
    'obligation_id',
    'amount_cop',
    'created_by',
    'reversed_at',
    'reversed_by',
    'reversal_reason',
])]
class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cop' => 'integer',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /**
     * @return BelongsTo<MonthlyObligation, $this>
     */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(MonthlyObligation::class, 'obligation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * Counts towards the balances.
     *
     * A reversed allocation does not. The row stays, because it is the record of
     * what somebody did and later undid.
     */
    public function isActive(): bool
    {
        return $this->reversed_at === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditMetadata(): array
    {
        return [
            'payment_id' => $this->payment_id,
            'obligation_id' => $this->obligation_id,
            'amount_cop' => $this->amount_cop,
        ];
    }
}

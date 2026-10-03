<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Payments\PaymentMethod;
use App\Domain\Payments\ReconciliationState;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money received for a client.
 *
 * The header names a client and no company, which is a modelling decision rather
 * than an omission. A client may transfer between employers, pay several months at
 * once, and settle obligations that belong to different companies. Binding the
 * header to one company would force that payment to be split, or refused, and
 * either would misdescribe the money.
 *
 * What the money pays for is entirely the allocations' business.
 *
 * ## Never deleted
 *
 * A payment that turns out to be wrong is voided, which keeps every row and every
 * allocation while removing the money from the economic totals. A deleted payment
 * would be indistinguishable from one that never arrived, which is the one
 * distinction that matters here.
 *
 * @property int $id
 * @property int $client_id
 * @property int $amount_cop
 * @property Carbon $received_on
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 */
#[Fillable([
    'client_id',
    'amount_cop',
    'received_on',
    'method',
    'reference',
    'notes',
    'created_by',
    'voided_at',
    'voided_by',
    'void_reason',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cop' => 'integer',
            'received_on' => 'date',
            'method' => PaymentMethod::class,
            'voided_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Whole pesos applied to obligations, from allocations that are still live and
     * still held.
     *
     * The sum is derived, never stored. A stored total would be a second answer to
     * a question that has to be exact, and it would be wrong the moment an
     * allocation is reversed or a payment is voided.
     *
     * @param  Collection<int, PaymentAllocation>|null  $allocations
     */
    public function allocatedAmount(?iterable $allocations = null): int
    {
        // A voided payment applied nothing. Its allocation rows are kept — the record
        // of what was once applied has to survive — but counting them would make the
        // payment claim money it no longer holds, and would disagree with every
        // balance derived from the same ledger.
        if ($this->isVoided()) {
            return 0;
        }

        $rows = $allocations ?? $this->allocations()->get();

        return (int) collect($rows)
            ->filter(fn (PaymentAllocation $allocation): bool => $allocation->isActive())
            ->sum('amount_cop');
    }

    /**
     * What this payment still owes nothing for: money received and not yet applied.
     *
     * Positive means an advance, a prepayment, or a reconciliation still to do. It
     * is not an error and it is not money to be discarded; it stays available for a
     * future obligation until somebody allocates it.
     */
    public function unallocatedAmount(?iterable $allocations = null): int
    {
        if ($this->isVoided()) {
            return 0;
        }

        return $this->amount_cop - $this->allocatedAmount($allocations);
    }

    public function reconciliationState(?iterable $allocations = null): ReconciliationState
    {
        return ReconciliationState::for($this->amount_cop, $this->allocatedAmount($allocations), $this->isVoided());
    }

    /**
     * Whether this payment still has money that has not been applied.
     *
     * This is what "requires reconciliation" means, and it is not a fault: a
     * prepayment is exactly this.
     */
    public function requiresReconciliation(?iterable $allocations = null): bool
    {
        return ! $this->isVoided() && $this->unallocatedAmount($allocations) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditMetadata(): array
    {
        return [
            'client_id' => $this->client_id,
            'amount_cop' => $this->amount_cop,
            'received_on' => $this->received_on?->format('Y-m-d'),
            'method' => $this->method->value,
        ];
    }
}

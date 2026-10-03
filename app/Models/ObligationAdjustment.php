<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\AdjustmentType;
use Database\Factories\ObligationAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed correction to an obligation's effective amount.
 *
 * The ledger is append-only. A posted adjustment is never edited and never
 * deleted: correcting one writes a reversal with the opposite `delta_cop` and a
 * pointer to the row it undoes, so the sequence of what somebody believed, and
 * when, is recoverable. A row that was edited in place would leave no trace that
 * a correction had been needed at all.
 *
 * The sign lives in the column rather than in the type, because a discount and a
 * surcharge are the same operation in opposite directions and the arithmetic
 * should not have to know which is which. `AdjustmentType` says why.
 *
 * @property int $id
 * @property int $obligation_id
 * @property AdjustmentType $type
 * @property int $delta_cop
 * @property int|null $reverses_adjustment_id
 */
#[Fillable([
    'obligation_id',
    'type',
    'delta_cop',
    'reason',
    'created_by',
    'reverses_adjustment_id',
])]
class ObligationAdjustment extends Model
{
    /** @use HasFactory<ObligationAdjustmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AdjustmentType::class,
            'delta_cop' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MonthlyObligation, $this>
     */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(MonthlyObligation::class, 'obligation_id');
    }

    /**
     * @return BelongsTo<ObligationAdjustment, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(ObligationAdjustment::class, 'reverses_adjustment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReversal(): bool
    {
        return $this->type === AdjustmentType::Reversal;
    }

    /**
     * An adjustment that has not been undone.
     *
     * A reversal is itself active: it counts in the balance, with the opposite
     * sign. "Active" therefore means "counted", not "not a reversal".
     */
    public function isActive(): bool
    {
        return $this->reverses_adjustment_id === null
            || ObligationAdjustment::query()
                ->where('reverses_adjustment_id', $this->id)
                ->doesntExist();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditMetadata(): array
    {
        return [
            'obligation_id' => $this->obligation_id,
            'type' => $this->type->value,
            'delta_cop' => $this->delta_cop,
            'reverses_adjustment_id' => $this->reverses_adjustment_id,
        ];
    }
}

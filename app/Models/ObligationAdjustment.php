<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\AdjustmentType;
use Database\Factories\ObligationAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
    /**
     * The row that reverses this one.
     *
     * The other end of the self-reference. Without it, "has this been undone?" can only be
     * answered by scanning every adjustment, which is why the published API published
     * `reversed_at` columns that do not exist in the schema.
     *
     * @return HasOne<self, $this>
     */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_adjustment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether this row is the reversal of another.
     *
     * True only for the row the system writes when somebody undoes something. It is not a
     * state an original passes through; it is a different row.
     */
    public function isReversal(): bool
    {
        return $this->reverses_adjustment_id !== null;
    }

    /**
     * Whether this adjustment is still in force.
     *
     * An adjustment is in force when **no** reversal row points at it. The earlier version
     * asked a different question — "am I not myself a reversal?" — which describes the shape
     * of the row rather than whether its effect survives, and so reported an original that
     * had been undone as still active. The interface then offered to reverse it a second
     * time, and the second reversal was refused by the unique index on
     * `reverses_adjustment_id` while the screen insisted it was possible.
     *
     * The answer comes from the relation, never from a marker column: the database has no
     * `reversed_at` on this table, because a reversal is a row and not a state.
     */
    public function isActive(): bool
    {
        return $this->reversedBy === null;
    }

    /**
     * The reversal row that undoes this one, if it has been undone.
     */
    public function reversal(): ?self
    {
        return $this->reversedBy;
    }

    /**
     * Whether somebody may reverse this adjustment again.
     *
     * False for a row that is already undone, and false for a reversal row itself: reversing
     * a reversal is not a thing this ledger does, because the correct response to undoing a
     * correction is to write a new correction.
     */
    public function canBeReversed(): bool
    {
        return ! $this->isReversal() && $this->isActive();
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

<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * Why an adjustment was made, and which way it may point.
 *
 * The type explains; the direction lives in `delta_cop`, which is signed. What A03-R1 adds
 * is that the two are no longer independent: each type now says which directions it
 * accepts, and the domain refuses the combination the label does not describe.
 *
 * ## The contract
 *
 *     discount    must reduce what is owed   (negative)
 *     credit      must reduce what is owed   (negative)
 *     surcharge   must increase what is owed (positive)
 *     correction  may do either
 *     reversal    system-only, never chosen
 *
 * Before this, the type carried a hint (`usuallyReduces()`) that nothing enforced, and the
 * interface forced a sign: a discount was typed as a positive number and negated by hand.
 * An operator who wanted a negative correction had no way to express it, and a surcharge
 * entered as `-50000` was silently recorded as `+50000`. That is arithmetic disagreeing with
 * a label, which is the kind of thing an audit eventually finds and nobody can explain.
 *
 * ## Why `credit` reduces
 *
 * A credit note given to a client reduces what they owe. It is distinct from a discount in
 * why it happened — a commercial concession versus a billing correction — and both are
 * negative, because both move money towards the client.
 *
 * ## Why `reversal` is not selectable
 *
 * It is written by `AdjustObligation::reverse()` when somebody undoes something. There is
 * no such thing as an operator deciding to write a reversal directly, and accepting one
 * would allow a reversal with no target, which the database's `reversal_shape_check`
 * refuses anyway. It is in the enum because it is stored in the same column.
 */
enum AdjustmentType: string
{
    case Discount = 'discount';
    case Surcharge = 'surcharge';
    case Correction = 'correction';
    case Credit = 'credit';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Discount => 'Descuento',
            self::Surcharge => 'Recargo',
            self::Correction => 'Corrección',
            self::Credit => 'Crédito',
            self::Reversal => 'Reversión',
        };
    }

    /**
     * The types a person may choose.
     *
     * Published as a vocabulary so the interface does not keep its own list, which is how a
     * fourth type ends up missing from the dropdown while the API accepts it.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [
            self::Correction,
            self::Discount,
            self::Surcharge,
            self::Credit,
        ];
    }

    /**
     * Whether this type is one a person chooses, as opposed to one the system writes.
     */
    public function isReversal(): bool
    {
        return $this === self::Reversal;
    }

    /**
     * Whether the type must reduce what is owed.
     */
    public function mustReduce(): bool
    {
        return $this === self::Discount || $this === self::Credit;
    }

    /**
     * Whether the type must increase what is owed.
     */
    public function mustIncrease(): bool
    {
        return $this === self::Surcharge;
    }

    /**
     * Whether either direction is acceptable.
     */
    public function allowsEitherDirection(): bool
    {
        return $this === self::Correction;
    }

    /**
     * Backwards-compatible reading of the old hint, now a statement of the contract rather
     * than an unverified assumption.
     */
    public function usuallyReduces(): bool
    {
        return $this->mustReduce();
    }

    /**
     * Refuse a direction this type does not describe.
     *
     * Called from the domain rather than the FormRequest, because the importer has no
     * FormRequest and the same rule has to hold on both paths.
     */
    public function assertDirection(int $deltaCop): void
    {
        if ($this->isReversal()) {
            throw AdjustmentRejected::reversalIsNotSelectable();
        }

        if ($deltaCop === 0) {
            throw AdjustmentRejected::deltaMustNotBeZero();
        }

        if ($this->mustReduce() && $deltaCop > 0) {
            throw AdjustmentRejected::directionForbidden($this, 'reducir');
        }

        if ($this->mustIncrease() && $deltaCop < 0) {
            throw AdjustmentRejected::directionForbidden($this, 'aumentar');
        }
    }
}

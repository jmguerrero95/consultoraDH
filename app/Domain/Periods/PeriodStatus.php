<?php

declare(strict_types=1);

namespace App\Domain\Periods;

/**
 * Whether a monthly period still accepts structural changes.
 *
 * Two states, and only two, because the business distinguishes exactly two things
 * about a month: whether obligations can still be generated into it, and whether
 * money can move against what was generated.
 *
 * There is no `processing` state. One was considered, for a period being generated
 * asynchronously, and rejected: a third state would need a clock and an operator to
 * clear it, and a month stuck in `processing` is a month nobody can generate for.
 * Generation is a transaction that either completes or does not, so the period is
 * open throughout and the obligations either exist or do not.
 *
 * ## What closed does and does not mean
 *
 * Closed freezes **structure**: the set of obligations, their base amounts, their
 * due dates, and the membership that produced them.
 *
 * It does not freeze **money**. A debt generated in March is paid in July, and
 * that payment has nothing to do with whether March can still generate an
 * obligation. Freezing payments too would make March's debt unpayable without a
 * reopening, which is not what "closed" means to anybody who has to pay it.
 */
enum PeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::Closed => 'Cerrado',
        };
    }

    /** A closed period rejects generation, regeneration and structural edits. */
    public function acceptsStructuralChange(): bool
    {
        return $this === self::Open;
    }

    /** Both states accept payments, allocations and adjustments. */
    public function acceptsFinancialActivity(): bool
    {
        return true;
    }
}

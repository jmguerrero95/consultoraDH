<?php

declare(strict_types=1);

namespace App\Domain\Reports\LegacyWorkbook;

/**
 * The financial state of one person in one month, as the export needs to show it.
 *
 * ## The states, and what each one is allowed to claim
 *
 * | State | Claimed when | Fill |
 * |---|---|---|
 * | `paid` | an obligation exists **and** valid allocations cover it | green |
 * | `pending` | an obligation exists, is partly or wholly unpaid, and is not yet due | amber |
 * | `overdue` | an obligation exists, is unpaid, and its due date has passed | red |
 * | `unknown` | **no obligation row exists for that month** | none |
 *
 * ## Why `unknown` is not a fifth colour and not a grey one that reads as "pending"
 *
 * Because the absence of an obligation is not a fact about the payment. It means the system has no
 * record — the rate was never configured, the period was never opened, the planilla was not
 * generated. C.3 is explicit that this implies neither paid nor delinquent, and the cell is left
 * uncoloured with `SIN INFORMACIÓN DE PAGO` in the novelty column. A neutral fill would be read as
 * "pending" by every person who has ever seen a spreadsheet.
 *
 * ## Why an unallocated payment is not `paid`
 *
 * A payment that exists but is not applied to the obligation does not discharge it. The export
 * counts **allocations**, not payments, because allocations are what the financial system says
 * were applied — and `payment_allocations` has `reversed_at` for the ones that were undone, which
 * is the part a payment total would quietly get wrong.
 */
enum LegacyPaymentStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Overdue = 'overdue';
    case Unknown = 'unknown';

    /** The fill colour, as ARGB. `null` means "leave the cell alone". */
    public function fill(): ?string
    {
        return match ($this) {
            self::Paid => 'FF00B050',
            self::Pending => 'FFFFFF00',
            self::Overdue => 'FFFF0000',
            // Null, not grey. See the class docblock.
            self::Unknown => null,
        };
    }

    /** The word written in the NOVEDAD column, or null when there is nothing to say. */
    public function novelty(): ?string
    {
        return match ($this) {
            self::Paid => null,
            self::Pending => 'PENDIENTE',
            self::Overdue => 'VENCIDO',
            self::Unknown => 'SIN INFORMACIÓN DE PAGO',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Pagado',
            self::Pending => 'Pendiente',
            self::Overdue => 'Vencido',
            self::Unknown => 'Sin información de pago',
        };
    }

    /**
     * Decide from the obligation and what has been applied to it.
     *
     * @param  int  $baseAmount  the obligation's amount; null when there is no obligation row
     * @param  int  $allocated  valid, unreversed allocations against that obligation
     * @param  string|null  $dueOn  the obligation's due date, `Y-m-d`
     * @param  string  $today  `Y-m-d`, injected so the result is testable
     */
    public static function decide(
        ?int $baseAmount,
        int $allocated,
        ?string $dueOn,
        string $today,
    ): self {
        // No obligation at all: the system has nothing to say about this month.
        if ($baseAmount === null) {
            return self::Unknown;
        }

        // Fully covered. A partial payment that has already passed its due date is `overdue`, not
        // `pending` — the money that is missing is late whatever else is true.
        if ($allocated >= $baseAmount) {
            return self::Paid;
        }

        return $dueOn !== null && $dueOn < $today
            ? self::Overdue
            : self::Pending;
    }
}

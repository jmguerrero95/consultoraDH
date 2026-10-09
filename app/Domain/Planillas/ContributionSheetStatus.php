<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

/**
 * A planilla's lifecycle.
 *
 * ## Why the transitions live here and not in a controller
 *
 * §17 asks for explicit domain actions rather than a generic PATCH that sets a status. That is not
 * only about API shape: `draft -> ready` requires a validation pass to have succeeded, `ready ->
 * submitted` requires a reference and a date, and `paid -> draft` must be impossible because a paid
 * month is history. If the rules lived in a controller, the second endpoint that touched a planilla
 * would have to remember all of them.
 *
 * So the table below is the authority, and `ContributionSheet` refuses any move not in it.
 *
 * ## Why `paid` has no ordinary exit
 *
 * §17: "paid is historical. Do not rewrite a paid planilla into draft." A correction to a paid month
 * is a *new* record — usually in a later period — because the alternative is editing the record of a
 * payment that already happened in the bank.
 */
enum ContributionSheetStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Submitted = 'submitted';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /** Whether this answer is allowed at all for this code at all. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isTerminal(): bool
    {
        return $this === self::Paid || $this === self::Cancelled;
    }

    /** Can the sheet move to `$to`? */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Reopening is a separate, audited action rather than a side effect of editing: a
            // validated snapshot must not be quietly mutable again.
            self::Draft => [self::Ready, self::Cancelled],
            self::Ready => [self::Draft, self::Submitted, self::Cancelled],
            self::Submitted => [self::Paid, self::Cancelled],
            // §17: historical.
            self::Paid => [],
            self::Cancelled => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Ready => 'Lista para enviar',
            self::Submitted => 'Enviada',
            self::Paid => 'Pagada',
            self::Cancelled => 'Cancelada',
        };
    }

    /** @return list<string> */
    public static function vocabulary(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}

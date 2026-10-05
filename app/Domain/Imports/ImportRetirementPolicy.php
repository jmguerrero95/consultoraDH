<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * How a `RETIRO 15 DIAS MARZO` note becomes a date, if it does.
 *
 * ## Why this is a decision and not a rule
 *
 * §8.2 is unambiguous: the number in a withdrawal note is **not** a day. In this source it
 * is a count of contributed days, and nothing in the workbook says so — the reader would be
 * guessing. Turning 15 into `15/03` would invent a fact, and the consequence would be
 * invisible: a relationship would close on a day nobody chose, and a month's obligation
 * would land on the wrong side of it.
 *
 * So the safe answer is the default and the only derivation offered is one that uses
 * nothing but the month, which the note *does* state.
 *
 * ## `month_end_boundary`
 *
 * The relationship stops being effective on the **first day of the month after** the one
 * named. That is the only claim the source supports: "employed through March" means not
 * employed in April. The stored date is exact and the precision is `month`, so the
 * interface says "Abril 2026 (mes aproximado)" rather than inventing a day inside March.
 *
 * ## What is deliberately absent
 *
 * There is no 30-day rule and no PILA-style window. §8.2 forbids it in code, and inventing
 * one here is exactly the failure the spec is guarding against. If the business later
 * confirms that the number means contributed days, that is a new case chosen deliberately,
 * not a third option discovered while writing this enum.
 */
enum ImportRetirementPolicy: string
{
    /** Default. Record that a withdrawal was observed; derive no date. */
    case ManualOnly = 'manual_only';

    /** The relationship ends on the first day of the month after the one named. */
    case MonthEndBoundary = 'month_end_boundary';

    public function label(): string
    {
        return match ($this) {
            self::ManualOnly => 'Sólo registrar el retiro (recomendado)',
            self::MonthEndBoundary => 'Cerrar el primer día del mes siguiente',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ManualOnly => 'La nota de retiro se conserva como evidencia y no se deriva ninguna fecha. '
                .'Cada cierre se decide a mano.',
            self::MonthEndBoundary => 'La relación deja de ser efectiva el primer día del mes siguiente al mes '
                .'mencionado, y la fecha queda marcada como aproximada.',
        };
    }

    public function derivesDate(): bool
    {
        return $this === self::MonthEndBoundary;
    }

    public static function default(): self
    {
        return self::ManualOnly;
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $policy): array => [
                'value' => $policy->value,
                'label' => $policy->label(),
                'description' => $policy->description(),
            ],
            self::cases(),
        );
    }
}

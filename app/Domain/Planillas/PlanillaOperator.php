<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

/**
 * Which operator a planilla was filed through.
 *
 * §10 is explicit that this is an operational selection made by a person and never guessed from the
 * company, because a company's filing route is not derivable from any data this repository holds.
 *
 * `other` exists because the real world has routes this milestone has no contract for. It requires a
 * name, because "other" with nothing after it is not a record of anything.
 */
enum PlanillaOperator: string
{
    case Simple = 'simple';
    case Arus = 'arus';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'SIMPLE',
            self::Arus => 'ARUS',
            self::Other => 'Otro',
        };
    }

    /** Does this operator need a name typed in? */
    public function requiresName(): bool
    {
        return $this === self::Other;
    }

    /** @return list<array{value: string, label: string, requires_name: bool}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'requires_name' => $case->requiresName(),
            ],
            self::cases(),
        );
    }
}

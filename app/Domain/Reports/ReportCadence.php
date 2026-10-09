<?php

declare(strict_types=1);

namespace App\Domain\Reports;

enum ReportCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Diario',
            self::Weekly => 'Semanal',
            self::Monthly => 'Mensual',
        };
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

<?php

declare(strict_types=1);

namespace App\Domain\Reports;

enum ReportFormat: string
{
    case Pdf = 'pdf';
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Csv => 'CSV',
            self::Xlsx => 'XLSX',
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

    public static function parse(string $value): self
    {
        return self::tryFrom($value) ?? throw Exceptions\UnknownReportFormat::for($value);
    }
}

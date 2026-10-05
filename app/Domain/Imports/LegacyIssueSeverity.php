<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * How loudly an issue is reported.
 *
 * Separate from `LegacyImportIssue::isBlocking()` on purpose, and the reason is in §18:
 * a `warning` can block and an `error` can be merely reported, so a single "severity"
 * column cannot answer both "does this stop me?" and "how should I present it?".
 */
enum LegacyIssueSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Información',
            self::Warning => 'Advertencia',
            self::Error => 'Error',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $severity): array => ['value' => $severity->value, 'label' => $severity->label()],
            self::cases(),
        );
    }
}

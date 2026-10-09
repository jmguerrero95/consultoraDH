<?php

declare(strict_types=1);

namespace App\Domain\Operations;

enum NoveltyStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::InProgress => 'En curso',
            self::Resolved => 'Resuelta',
            self::Cancelled => 'Cancelada',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::Cancelled;
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

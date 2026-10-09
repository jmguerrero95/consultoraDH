<?php

declare(strict_types=1);

namespace App\Domain\Operations;

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::InProgress => 'En curso',
            self::Done => 'Completada',
            self::Cancelled => 'Cancelada',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
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

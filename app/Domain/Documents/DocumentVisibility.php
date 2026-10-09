<?php

declare(strict_types=1);

namespace App\Domain\Documents;

enum DocumentVisibility: string
{
    case Internal = 'internal';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Interno',
            self::Client => 'Visible al cliente',
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

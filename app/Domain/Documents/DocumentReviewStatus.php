<?php

declare(strict_types=1);

namespace App\Domain\Documents;

enum DocumentReviewStatus: string
{
    case Received = 'received';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Recibido',
            self::Reviewed => 'Revisado',
            self::Approved => 'Aprobado',
            self::Rejected => 'Rechazado',
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

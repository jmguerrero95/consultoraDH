<?php

declare(strict_types=1);

namespace App\Domain\Documents;

enum DocumentRequestStatus: string
{
    case Requested = 'requested';
    case Received = 'received';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Solicitado',
            self::Received => 'Recibido',
            self::Reviewed => 'Revisado',
            self::Approved => 'Aprobado',
            self::Rejected => 'Rechazado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Approved || $this === self::Cancelled;
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

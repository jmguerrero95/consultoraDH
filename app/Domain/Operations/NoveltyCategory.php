<?php

declare(strict_types=1);

namespace App\Domain\Operations;

enum NoveltyCategory: string
{
    case Affiliation = 'affiliation';
    case Planilla = 'planilla';
    case Billing = 'billing';
    case Document = 'document';
    case Contact = 'contact';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Affiliation => 'Afiliación',
            self::Planilla => 'Planilla',
            self::Billing => 'Facturación',
            self::Document => 'Documento',
            self::Contact => 'Contacto',
            self::General => 'General',
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

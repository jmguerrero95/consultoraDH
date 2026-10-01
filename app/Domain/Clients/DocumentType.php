<?php

declare(strict_types=1);

namespace App\Domain\Clients;

/**
 * The kind of identity document a client presents.
 *
 * A closed set: the source data only ever contains these, and an unrecognised
 * value is a data quality question rather than a new document type.
 */
enum DocumentType: string
{
    case CedulaDeCiudadania = 'CC';
    case CedulaDeExtranjeria = 'CE';
    case TarjetaDeIdentidad = 'TI';
    case PermisoProteccionTemporal = 'PPT';
    case Pasaporte = 'PASSPORT';
    case Otro = 'OTHER';

    /**
     * What the interface shows.
     */
    public function label(): string
    {
        return match ($this) {
            self::CedulaDeCiudadania => 'Cédula de ciudadanía',
            self::CedulaDeExtranjeria => 'Cédula de extranjería',
            self::TarjetaDeIdentidad => 'Tarjeta de identidad',
            self::PermisoProteccionTemporal => 'Permiso por Protección Temporal',
            self::Pasaporte => 'Pasaporte',
            self::Otro => 'Otro',
        };
    }

    /**
     * Short label for dense tables, where the full wording does not fit.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::CedulaDeCiudadania => 'C.C.',
            self::CedulaDeExtranjeria => 'C.E.',
            self::TarjetaDeIdentidad => 'T.I.',
            self::PermisoProteccionTemporal => 'PPT',
            self::Pasaporte => 'Pasaporte',
            self::Otro => 'Otro',
        };
    }

    /**
     * The types whose number is a purely numeric Colombian document.
     *
     * These are the ones the spreadsheet writes with thousands separators, so
     * normalisation has something to do for them.
     */
    public function isNumericColombian(): bool
    {
        return match ($this) {
            self::CedulaDeCiudadania,
            self::CedulaDeExtranjeria,
            self::TarjetaDeIdentidad,
            self::PermisoProteccionTemporal => true,
            self::Pasaporte,
            self::Otro => false,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}

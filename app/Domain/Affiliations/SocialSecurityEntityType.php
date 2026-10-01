<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

/**
 * The four kinds of entity a Colombian worker can be affiliated to.
 *
 * The type is not free text: it is what makes an affiliation meaningful, so it
 * lives in a closed set that is mirrored by a CHECK constraint on
 * `social_security_entities.type` and on `client_affiliations.type`.
 */
enum SocialSecurityEntityType: string
{
    case Eps = 'EPS';
    case Afp = 'AFP';
    case Arl = 'ARL';
    case Ccf = 'CCF';

    /**
     * The wording the interface uses in full.
     *
     * CCF is expanded rather than shown as the acronym: an administrator
     * looking at a catalogue entry needs the concept, not the code.
     */
    public function label(): string
    {
        return match ($this) {
            self::Eps => 'Entidad Prestadora de Salud',
            self::Afp => 'Administradora de Fondos de Pensiones',
            self::Arl => 'Administradora de Riesgos Laborales',
            self::Ccf => 'Caja de Compensación Familiar',
        };
    }

    /**
     * The short form, used in tables and filters.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Eps => 'EPS',
            self::Afp => 'AFP',
            self::Arl => 'ARL',
            self::Ccf => 'Caja de Compensación Familiar',
        };
    }

    /**
     * Only an ARL carries a risk class.
     *
     * An affiliation that is anything other than an ARL must leave
     * `arl_risk_class` empty, which the database enforces and the domain layer
     * refuses to write.
     */
    public function carriesRiskClass(): bool
    {
        return $this === self::Arl;
    }

    /**
     * @return list<array{value: string, label: string, short_label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'short_label' => $type->shortLabel(),
            ],
            self::cases(),
        );
    }
}

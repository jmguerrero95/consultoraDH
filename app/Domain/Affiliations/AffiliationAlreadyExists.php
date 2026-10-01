<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Models\Client;
use App\Models\ClientAffiliation;
use RuntimeException;

/**
 * The client already has an open affiliation of that type.
 *
 * Its own exception, because the interface responds to it with a real choice:
 * close the current one and open the new one, or cancel. One open EPS per client
 * is also a partial unique index, so this cannot be bypassed by a second
 * concurrent request; the exception is what turns that into a clear message
 * instead of a database error surfacing as a 500.
 */
final class AffiliationAlreadyExists extends RuntimeException
{
    private function __construct(
        public readonly SocialSecurityEntityType $type,
        public readonly int $existingAffiliationId,
        public readonly int $existingEntityId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forType(
        Client $client,
        SocialSecurityEntityType $type,
        ClientAffiliation $existing,
    ): self {
        return new self(
            $type,
            $existing->id,
            $existing->social_security_entity_id,
            sprintf(
                'El cliente ya tiene una afiliación abierta de %s. Debe cerrarla o cambiarla de entidad.',
                $type->shortLabel(),
            ),
        );
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public function options(): array
    {
        return [
            [
                'value' => 'change',
                'label' => 'Cambiar de entidad',
                'description' => 'Cierra la afiliación actual en la fecha efectiva y abre la nueva.',
            ],
            [
                'value' => 'cancel',
                'label' => 'Cancelar',
                'description' => 'No modifica nada.',
            ],
        ];
    }
}

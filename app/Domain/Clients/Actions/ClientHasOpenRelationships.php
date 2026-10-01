<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Models\Client;
use RuntimeException;

/**
 * A client cannot be deactivated yet because relationships are still open.
 *
 * The interface answers this with a real decision: block and resolve the
 * relationships by hand, or deactivate and close them on a stated date. The count
 * travels with the exception so the warning can say what is in the way.
 */
final class ClientHasOpenRelationships extends RuntimeException
{
    private function __construct(
        public readonly int $clientId,
        public readonly int $openCount,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forClient(Client $client, int $openCount): self
    {
        return new self(
            $client->id,
            $openCount,
            sprintf(
                'El cliente tiene %d relación%s abierta%s con empresa%s. '
                .'Cierre la%s primero o use la desactivación con cierre de relaciones.',
                $openCount,
                $openCount === 1 ? '' : 'es',
                $openCount === 1 ? '' : 's',
                $openCount === 1 ? '' : 's',
                $openCount === 1 ? '' : 's',
            ),
        );
    }
}

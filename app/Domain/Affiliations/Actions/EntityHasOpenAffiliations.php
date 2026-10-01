<?php

declare(strict_types=1);

namespace App\Domain\Affiliations\Actions;

use RuntimeException;

/**
 * A catalogue entry still has open affiliations, so it cannot be deactivated.
 */
final class EntityHasOpenAffiliations extends RuntimeException
{
    public function __construct(public readonly int $openCount)
    {
        parent::__construct(sprintf(
            'La entidad tiene %d afiliación%s abierta%s. '
            .'Cierre%s o cámbiela%s de entidad antes de desactivarla.',
            $openCount,
            $openCount === 1 ? '' : 'es',
            $openCount === 1 ? '' : 's',
            $openCount === 1 ? 'la' : 'las',
            $openCount === 1 ? '' : 's',
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Models\Client;
use Illuminate\Support\Collection;

/**
 * The caller has to say which relationship they mean.
 *
 * A transfer moves a person from one company to another. When a client has a
 * single open relationship there is no question about which one, and the
 * convenient flow can proceed. When they have two or more, the choice is a
 * business decision that this application has no basis to make: closing the wrong
 * one would silently end an employment the operator never meant to touch.
 *
 * So it refuses, and it returns the open relationships so the interface can ask.
 * The dedicated endpoint, which names the assignment in its path, remains the way
 * to perform the transfer once the operator has chosen.
 */
final class TransferSourceRequired extends \RuntimeException
{
    /**
     * @param  Collection<int, ClientCompanyAssignment>  $open
     */
    private function __construct(
        public readonly Client $client,
        public readonly array $openAssignmentIds,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  Collection<int, ClientCompanyAssignment>  $open
     */
    public static function forClient(Client $client, $open): self
    {
        return new self(
            $client,
            $open->pluck('id')->all(),
            sprintf(
                'El cliente tiene %d relaciones abiertas. Indique cuál se transfiere: '
                .'este sistema no elige por usted.',
                $open->count(),
            ),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A client already has an open relationship and the caller did not choose what
 * to do about it.
 *
 * A dedicated exception rather than a generic validation failure, because the
 * interface has to respond to this one specially: it has to show which companies
 * are already open and ask the person to choose between transferring, keeping
 * both with a reason, or cancelling. The message is for the operator, and the
 * data it carries is for the interface.
 */
final class ParallelRelationshipNotAllowed extends RuntimeException
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
                'El cliente ya tiene %d relación%s abierta%s en otra empresa. '
                .'Debe decidir si transfiere, si mantiene las dos con justificación, o si cancela.',
                $open->count(),
                $open->count() === 1 ? '' : 'es',
                $open->count() === 1 ? '' : 's',
            ),
        );
    }

    /**
     * The three choices the interface offers.
     *
     * @return list<array{value: string, label: string, description: string, requires_reason: bool}>
     */
    public function options(): array
    {
        return [
            [
                'value' => 'transfer',
                'label' => 'Transferir',
                'description' => 'Cierra la relación abierta en la fecha efectiva y abre la nueva.',
                'requires_reason' => false,
            ],
            [
                'value' => 'parallel',
                'label' => 'Mantener en paralelo',
                'description' => 'Conserva la relación abierta y agrega la nueva, con justificación.',
                'requires_reason' => true,
            ],
            [
                'value' => 'cancel',
                'label' => 'Cancelar',
                'description' => 'No modifica nada.',
                'requires_reason' => false,
            ],
        ];
    }
}

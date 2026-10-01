<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Affiliations\ManageClientCompanies;
use App\Domain\Clients\Events\ClientStatusChanged;
use App\Domain\Shared\RecordStatus;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Activates or deactivates a client.
 *
 * Deactivating a client is not a cosmetic flag, and the choice of what to do with
 * the open relationships is the interesting part. Two shapes are available:
 *
 *   'block'    refuse while any relationship is open. Nothing changes and the
 *              operator is told exactly what is in the way.
 *   'close'    close every open relationship on an effective date and deactivate
 *              in one transaction.
 *
 * The interface asks which one to use rather than guessing. Silently closing a
 * person's employment would be the worst of the available behaviours: it would
 * destroy the fact that they worked there, and it would do so without anyone
 * having decided it.
 */
final class SetClientStatus
{
    public function __construct(
        private readonly ManageClientCompanies $relationships,
    ) {}

    public function execute(
        Client $client,
        RecordStatus $to,
        User $actor,
        string $when = 'block',
        ?\DateTimeInterface $effectiveOn = null,
    ): Client {
        if ($client->status === $to) {
            return $client;
        }

        $open = $this->relationships->openAssignments($client);

        if ($to === RecordStatus::Inactive && $when === 'block' && $open->isNotEmpty()) {
            throw ClientHasOpenRelationships::forClient($client, $open->count());
        }

        return DB::transaction(function () use ($client, $to, $actor, $when, $open, $effectiveOn): Client {
            $from = $client->status->value;

            if ($to === RecordStatus::Inactive && $when === 'close') {
                $endedOn = $effectiveOn ?? new \DateTimeImmutable('today');

                foreach ($open as $assignment) {
                    $this->relationships->close(
                        $assignment,
                        $actor,
                        $endedOn,
                        'Cierre por desactivación del cliente',
                    );
                }
            }

            $client->forceFill(['status' => $to->value])->save();

            event(new ClientStatusChanged($client->refresh(), $actor, $from, $to->value));

            return $client->refresh();
        });
    }
}

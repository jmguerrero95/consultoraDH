<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Affiliations\ManageClientCompanies;
use App\Domain\Billing\BillingTopologyLock;
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
        private readonly BillingTopologyLock $topology = new BillingTopologyLock,
    ) {}

    /**
     * A03-R1: deactivation changes billing eligibility, so it holds the shared protocol.
     *
     * Generation treats an inactive client or company as "not a candidate for new debt".
     * Without this lock, generation can read a client as active, have that client
     * deactivated before it commits, and write the obligation anyway — a debt created
     * against eligibility that had already been withdrawn, at the exact moment it was
     * withdrawn. The reverse order is safe: the protocol serialises both directions, so
     * whoever takes the lock second sees the other's committed state.
     *
     * Reactivation is included deliberately. It makes the client a candidate again, which
     * is a change to the candidate set just as much as deactivation is.
     */
    public function execute(
        Client $client,
        RecordStatus $to,
        User $actor,
        string $when = 'block',
        ?\DateTimeInterface $effectiveOn = null,
    ): Client {
        return $this->topology->run(fn (): mixed => DB::transaction(
            function () use ($client, $to, $actor, $when, $effectiveOn): Client {
                // The client row is locked first and read again, and only then are the
                // open relationships read. R1 read them before the transaction, so the
                // decision could be taken against a state that had already changed: a
                // relationship opened by somebody else in the meantime was invisible
                // here, and 'block' deactivated a client with an open relationship.
                //
                // This is what serialises linking against deactivating, transferring
                // against deactivating, and closing against linking.
                $locked = Client::query()->lockForUpdate()->findOrFail($client->id);

                if ($locked->status === $to) {
                    return $locked;
                }

                $from = $locked->status->value;
                $open = $this->relationships->openAssignments($locked);

                if ($to === RecordStatus::Inactive && $when === 'block' && $open->isNotEmpty()) {
                    throw ClientHasOpenRelationships::forClient($locked, $open->count());
                }

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

                $locked->forceFill(['status' => $to->value])->save();

                event(new ClientStatusChanged($locked->refresh(), $actor, $from, $to->value));

                return $locked->refresh();
            }));
    }
}

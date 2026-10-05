<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

use App\Domain\Affiliations\Events\AffiliationChanged;
use App\Domain\Affiliations\Events\AffiliationClosed;
use App\Domain\Affiliations\Events\AffiliationCreated;
use App\Domain\Shared\LocksRow;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The operations that change a client's social security affiliations.
 *
 * Three invariants, enforced here as well as by the database:
 *
 *  1. The affiliation type must match the entity's type. An ARL affiliation may
 *     not point at an EPS entity. A CHECK constraint cannot look at another
 *     table, so this one is enforced in the domain layer in both directions, and
 *     the redundant `type` column lets the database check the rest.
 *
 *  2. Only an ARL may carry a risk level, and the level is 1..5, ordinal from
 *     lowest to highest risk. Class V is the highest class, not an absence of
 *     classification; NULL is the only representation of "not recorded", and it is
 *     allowed for any type.
 *
 *  3. History is closed, never overwritten. Changing entity closes the old row
 *     and opens a new one in one transaction.
 *
 *  4. A new open affiliation needs something still open itself: an active client
 *     and an active entity. Both checks are here and not only in the selectors of
 *     the interface, because a picker that filters is a convenience and a request
 *     that is refused is the rule. Rows that already exist keep pointing wherever
 *     they point: a closed historical affiliation to an entity that has since been
 *     deactivated is history, not an error.
 */
final class ManageAffiliations
{
    use LocksRow;

    /**
     * @throws DomainException when the type does not match or the risk is invalid
     * @throws AffiliationAlreadyExists when the type already has an open row and
     *                                  the caller did not ask to close it
     */
    public function create(
        Client $client,
        SocialSecurityEntity $entity,
        User $actor,
        ?\DateTimeInterface $startedOn = null,
        ?ArlRiskClass $riskClass = null,
        ?ClientCompanyAssignment $underAssignment = null,
        ?string $notes = null,
        bool $closeCurrent = false,
        ?\DateTimeInterface $closeCurrentOn = null,
    ): ClientAffiliation {
        $this->assertRiskAllowed($entity->type, $riskClass);

        if ($underAssignment !== null && $underAssignment->client_id !== $client->id) {
            throw new DomainException('La relación indicada pertenece a otro cliente.');
        }

        return DB::transaction(function () use (
            $client, $entity, $actor, $startedOn, $riskClass, $underAssignment, $notes,
            $closeCurrent, $closeCurrentOn
        ): ClientAffiliation {
            // The client row is locked and re-read, in the documented order, and the
            // status decision is taken from the copy read under the lock. Checking
            // the instance the caller was holding meant a client deactivated a
            // moment ago could still be given a new open affiliation.
            $client = $this->lockClient($client);

            // Then the entity, which is the second participant in an affiliation for
            // the same reason the company is locked when a relationship is opened.
            // Its status and its type both decide what happens here, and both were
            // being read from a copy that could already be stale.
            $entity = $this->lockEntity($entity);

            if (! $client->isActive()) {
                throw new DomainException('No se puede registrar una afiliación para un cliente inactivo.');
            }

            if (! $entity->isActive()) {
                throw new DomainException(sprintf(
                    'No se puede registrar una afiliación con la entidad %s porque está inactiva.',
                    $entity->name,
                ));
            }

            $type = $entity->type;

            $this->assertTypeMatches($type, $entity);
            $this->assertRiskAllowed($type, $riskClass);

            $current = $this->currentAffiliation($client, $type);

            if ($current !== null && ! $closeCurrent) {
                throw AffiliationAlreadyExists::forType($client, $type, $current);
            }

            $closed = null;

            if ($current !== null && $closeCurrent) {
                $endedOn = $closeCurrentOn ?? $startedOn ?? new \DateTimeImmutable('today');

                // The row is locked and re-read, and every decision comes from that
                // copy. `$current` was read under the client lock a moment ago, so it
                // is already fresh; the lock on the row itself is what keeps another
                // operation from closing it between the check and the write, and what
                // makes a replacement refuse a row that somebody else closed rather
                // than rewrite its closing date.
                $locked = $this->locked($current);

                if (! $locked->isActive()) {
                    throw new DomainException(
                        'La afiliación que se iba a reemplazar ya no está abierta: '
                        .'cerrarla de nuevo reescribiría una fecha de cierre ya registrada.'
                    );
                }

                if ($locked->started_on !== null
                    && $endedOn->format('Y-m-d') < $locked->started_on->format('Y-m-d')) {
                    throw new DomainException(
                        'La fecha de cierre no puede ser anterior al inicio de la afiliación actual.'
                    );
                }

                $locked->forceFill([
                    'ended_on' => $endedOn->format('Y-m-d'),
                    // A date a person chose is an exact day. The precision column exists so an
                    // import can mark a monthly inference; A02 never writes `month`.
                    'ended_on_precision' => 'day',
                ])->save();

                $closed = $locked->refresh();
            }

            $opened = ClientAffiliation::query()->create([
                'client_id' => $client->id,
                'social_security_entity_id' => $entity->id,
                'client_company_assignment_id' => $underAssignment?->id,
                'type' => $type->value,
                'started_on' => $startedOn?->format('Y-m-d'),
                // A start nobody can date is not a day-precision start with a missing value:
                // saying `day` here would claim an exactness the row does not have, and the
                // coherence CHECK rejects it. §9.5 calls this `started_on = null` with
                // `precision = unknown`.
                'started_on_precision' => $startedOn === null ? 'unknown' : 'day',
                'ended_on' => null,
                'ended_on_precision' => null,
                'arl_risk_class' => $riskClass?->value,
                'notes' => $notes,
            ]);

            if ($closed === null) {
                event(new AffiliationCreated($opened, $actor));
            } else {
                event(new AffiliationChanged(
                    $closed,
                    $opened,
                    $actor,
                    $closed->ended_on?->format('Y-m-d') ?? '',
                ));
            }

            return $opened;
        });
    }

    /**
     * Move a client to another entity of the same type.
     *
     * Closes the current row and opens a new one, in one transaction, so there is
     * no moment at which the client has no affiliation of that type.
     */
    public function changeEntity(
        ClientAffiliation $current,
        SocialSecurityEntity $to,
        User $actor,
        \DateTimeInterface $effectiveOn,
        ?ArlRiskClass $riskClass = null,
        ?string $notes = null,
    ): ClientAffiliation {
        if (! $current->isActive()) {
            throw new DomainException('Sólo se puede cambiar una afiliación abierta.');
        }

        if ($to->type !== $current->type) {
            throw new DomainException(sprintf(
                'No se puede cambiar una afiliación de %s a una entidad de %s.',
                $current->type->value,
                $to->type->value,
            ));
        }

        if ($to->id === $current->social_security_entity_id) {
            throw new DomainException('El cliente ya está afiliado a esa entidad.');
        }

        if ($current->started_on !== null
            && $effectiveOn->format('Y-m-d') < $current->started_on->format('Y-m-d')) {
            throw new DomainException('La fecha efectiva no puede ser anterior al inicio de la afiliación.');
        }

        return DB::transaction(function () use ($current, $to, $actor, $effectiveOn, $riskClass, $notes) {
            // Changing entity closes one row and opens another, so it is creating a
            // new open affiliation and it obeys the same rule as `create()`: the
            // client is locked and re-read first, then the decision is taken from
            // that copy.
            $client = $this->lockClient($current->client);

            if (! $client->isActive()) {
                throw new DomainException('No se puede cambiar una afiliación de un cliente inactivo.');
            }

            // Then the destination entity, in the documented order. "Usable now" has
            // to mean under this lock: moving somebody to an entity deactivated a
            // moment ago would record an affiliation nobody can maintain.
            $to = $this->lockEntity($to);

            if (! $to->isActive()) {
                throw new DomainException(sprintf(
                    'No se puede cambiar la afiliación a la entidad %s porque está inactiva.',
                    $to->name,
                ));
            }

            $this->assertTypeMatches($to->type, $to);
            $this->assertRiskAllowed($to->type, $riskClass);

            $closed = $this->locked($current);

            if (! $closed->isActive()) {
                throw new DomainException('La afiliación de origen ya no está abierta.');
            }

            $closed->forceFill([
                'ended_on' => $effectiveOn->format('Y-m-d'),
                'ended_on_precision' => 'day',
            ])->save();

            $opened = ClientAffiliation::query()->create([
                'client_id' => $closed->client_id,
                'social_security_entity_id' => $to->id,
                'client_company_assignment_id' => $closed->client_company_assignment_id,
                'type' => $closed->type->value,
                'started_on' => $effectiveOn->format('Y-m-d'),
                'started_on_precision' => 'day',
                'ended_on' => null,
                'ended_on_precision' => null,
                'arl_risk_class' => $riskClass?->value,
                'notes' => $notes ?? $closed->notes,
            ]);

            event(new AffiliationChanged($closed->refresh(), $opened, $actor, $effectiveOn->format('Y-m-d')));

            return $opened;
        });
    }

    /**
     * Close an open affiliation on a given date, keeping the row.
     *
     * The row is closed; nothing is deleted and nothing is overwritten. That is why
     * the decision has to be taken from the row read under its own lock and not from
     * the instance the caller was handed: a copy taken while the row was open says
     * `ended_on = NULL`, and writing to it after somebody else recorded a real
     * closing date replaced that date with this one. The history stopped being a
     * record of what happened and became a record of whichever request arrived last.
     *
     * The order is the documented one: client, then the history row. The client lock
     * is taken first so that every affiliation write for one client serialises, so
     * `create`, `changeEntity` and `close` cannot interleave over the same rows.
     *
     * The date is validated against the locked row as well, for the same reason: an
     * end date before a start date recorded by a later operation is not a fact about
     * the past, it is a contradiction.
     */
    public function close(
        ClientAffiliation $affiliation,
        User $actor,
        \DateTimeInterface $endedOn,
        string $reason = 'Cierre manual',
    ): ClientAffiliation {
        return DB::transaction(function () use ($affiliation, $actor, $endedOn, $reason): ClientAffiliation {
            $this->lockClient($affiliation->client);

            $locked = $this->locked($affiliation);

            // Read under the lock, which is the whole point. The caller's copy may
            // have been taken before this transaction waited, and its answer to
            // "is this still open?" is not evidence.
            if (! $locked->isActive()) {
                throw new DomainException('La afiliación ya estaba cerrada.');
            }

            if ($locked->started_on !== null
                && $endedOn->format('Y-m-d') < $locked->started_on->format('Y-m-d')) {
                throw new DomainException('La fecha de cierre no puede ser anterior al inicio de la afiliación.');
            }

            $locked->forceFill([
                'ended_on' => $endedOn->format('Y-m-d'),
                'ended_on_precision' => 'day',
            ])->save();

            event(new AffiliationClosed($locked->refresh(), $actor, $reason));

            return $locked->refresh();
        });
    }

    /**
     * The open affiliation of a given type for a client, if there is one.
     */
    public function currentAffiliation(Client $client, SocialSecurityEntityType $type): ?ClientAffiliation
    {
        return ClientAffiliation::query()
            ->where('client_id', $client->id)
            ->where('type', $type->value)
            ->whereNull('ended_on')
            ->orderByDesc('started_on')
            ->first();
    }

    /**
     * The affiliation type must be the entity's type.
     */
    private function assertTypeMatches(SocialSecurityEntityType $type, SocialSecurityEntity $entity): void
    {
        if ($entity->type !== $type) {
            throw new DomainException(sprintf(
                'La entidad indicada es de tipo %s y no de %s.',
                $entity->type->value,
                $type->value,
            ));
        }
    }

    /**
     * Only an ARL carries a risk level.
     */
    private function assertRiskAllowed(SocialSecurityEntityType $type, ?ArlRiskClass $riskClass): void
    {
        if ($riskClass !== null && ! $type->carriesRiskClass()) {
            throw new DomainException(sprintf(
                'El nivel de riesgo sólo aplica a afiliaciones de ARL, no a %s.',
                $type->shortLabel(),
            ));
        }
    }
}

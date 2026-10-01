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
 *  2. Only an ARL may carry a risk level, and the level is 1..5. Level V means
 *     "not classified"; NULL means genuinely unknown, which is allowed for any
 *     type.
 *
 *  3. History is closed, never overwritten. Changing entity closes the old row
 *     and opens a new one in one transaction.
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
        $type = $entity->type;

        $this->assertTypeMatches($type, $entity);
        $this->assertRiskAllowed($type, $riskClass);

        if ($underAssignment !== null && $underAssignment->client_id !== $client->id) {
            throw new DomainException('La relación indicada pertenece a otro cliente.');
        }

        return DB::transaction(function () use (
            $client, $entity, $actor, $startedOn, $riskClass, $underAssignment, $notes,
            $closeCurrent, $closeCurrentOn, $type
        ): ClientAffiliation {
            $current = $this->currentAffiliation($client, $type);

            if ($current !== null && ! $closeCurrent) {
                throw AffiliationAlreadyExists::forType($client, $type, $current);
            }

            $closed = null;

            if ($current !== null && $closeCurrent) {
                $endedOn = $closeCurrentOn ?? $startedOn ?? new \DateTimeImmutable('today');

                if ($current->started_on !== null
                    && $endedOn->format('Y-m-d') < $current->started_on->format('Y-m-d')) {
                    throw new DomainException(
                        'La fecha de cierre no puede ser anterior al inicio de la afiliación actual.'
                    );
                }

                $locked = $this->locked($current);

                $locked->forceFill(['ended_on' => $endedOn->format('Y-m-d')])->save();

                $closed = $locked->refresh();
            }

            $opened = ClientAffiliation::query()->create([
                'client_id' => $client->id,
                'social_security_entity_id' => $entity->id,
                'client_company_assignment_id' => $underAssignment?->id,
                'type' => $type->value,
                'started_on' => $startedOn?->format('Y-m-d'),
                'ended_on' => null,
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

        $this->assertTypeMatches($to->type, $to);
        $this->assertRiskAllowed($to->type, $riskClass);

        if ($to->id === $current->social_security_entity_id) {
            throw new DomainException('El cliente ya está afiliado a esa entidad.');
        }

        if ($current->started_on !== null
            && $effectiveOn->format('Y-m-d') < $current->started_on->format('Y-m-d')) {
            throw new DomainException('La fecha efectiva no puede ser anterior al inicio de la afiliación.');
        }

        return DB::transaction(function () use ($current, $to, $actor, $effectiveOn, $riskClass, $notes) {
            $closed = $this->locked($current);

            if (! $closed->isActive()) {
                throw new DomainException('La afiliación de origen ya no está abierta.');
            }

            $closed->forceFill(['ended_on' => $effectiveOn->format('Y-m-d')])->save();

            $opened = ClientAffiliation::query()->create([
                'client_id' => $closed->client_id,
                'social_security_entity_id' => $to->id,
                'client_company_assignment_id' => $closed->client_company_assignment_id,
                'type' => $closed->type->value,
                'started_on' => $effectiveOn->format('Y-m-d'),
                'ended_on' => null,
                'arl_risk_class' => $riskClass?->value,
                'notes' => $notes ?? $closed->notes,
            ]);

            event(new AffiliationChanged($closed->refresh(), $opened, $actor, $effectiveOn->format('Y-m-d')));

            return $opened;
        });
    }

    /**
     * Close an open affiliation, keeping the row.
     */
    public function close(
        ClientAffiliation $affiliation,
        User $actor,
        \DateTimeInterface $endedOn,
        string $reason = 'Cierre manual',
    ): ClientAffiliation {
        if (! $affiliation->isActive()) {
            throw new DomainException('La afiliación ya estaba cerrada.');
        }

        if ($affiliation->started_on !== null
            && $endedOn->format('Y-m-d') < $affiliation->started_on->format('Y-m-d')) {
            throw new DomainException('La fecha de cierre no puede ser anterior al inicio de la afiliación.');
        }

        DB::transaction(function () use ($affiliation, $actor, $endedOn, $reason): void {
            $locked = $this->locked($affiliation);

            $locked->forceFill(['ended_on' => $endedOn->format('Y-m-d')])->save();

            event(new AffiliationClosed($locked->refresh(), $actor, $reason));
        });

        return $affiliation->refresh();
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

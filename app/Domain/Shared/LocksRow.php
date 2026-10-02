<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Models\Client;
use App\Models\Company;
use App\Models\SocialSecurityEntity;
use Illuminate\Database\Eloquent\Model;

/**
 * Row level locking for the historical records.
 *
 * Every write in A02 that touches a row of history has to take it out of reach of
 * a concurrent request first. A transfer closes one row and opens another; if two
 * operators transferred the same relationship at the same moment, both would read
 * it as open and both would open a new one, leaving the client with two open
 * relationships and no transfer having actually moved them.
 *
 * `SELECT ... FOR UPDATE` is what prevents that, and it has to be the row, not the
 * model instance the caller happens to be holding: the caller's copy may have been
 * read before the other transaction started.
 *
 * Used inside a transaction. A lock taken outside one is released immediately,
 * which makes it look like it worked.
 *
 * ## The order
 *
 *     client -> destination company -> destination entity -> history rows
 *
 * Master rows before history rows, always the client first, everywhere. Two
 * transactions that took the same locks in opposite orders would deadlock, and a
 * deadlock is a real failure even though PostgreSQL resolves it.
 *
 * ## Why the masters and not the history
 *
 * Locking the open assignments serialises only the operations that found rows. A
 * client's *first* relationship finds none, so it locks nothing and two concurrent
 * first relationships both insert. A master row always exists, so there is always
 * something to contend on.
 *
 * ## Why the caller must not trust its own copy
 *
 * Everything a model instance says may have been read before this transaction
 * started waiting for the lock. A client, a company or an entity deactivated by
 * somebody else a moment ago is still `active` on the instance that is about to
 * write. So each row is read again under its lock, and every decision is made from
 * that copy.
 */
trait LocksRow
{
    /**
     * Re-read this row under a write lock.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    private function locked(Model $model): Model
    {
        /** @var Model $fresh */
        $fresh = $model->newQuery()->lockForUpdate()->find($model->getKey());

        if ($fresh === null) {
            // Deleted between the read and the lock. Nothing to update, and
            // pretending otherwise would silently succeed.
            throw new \RuntimeException('El registro ya no existe.');
        }

        return $fresh;
    }

    /**
     * Lock the client master row and return it, read under that lock.
     */
    private function lockClient(Client $client): Client
    {
        /** @var Client|null $fresh */
        $fresh = Client::query()->lockForUpdate()->find($client->id);

        if ($fresh === null) {
            throw new \RuntimeException('El cliente ya no existe.');
        }

        return $fresh;
    }

    /**
     * Lock a company master row and return it, read under that lock.
     *
     * The company is a second participant in a relationship, not just the
     * destination: a link makes two master rows true at once. Checking the company
     * before the transaction meant a link could be created against a company that
     * another request deactivated in between, leaving an inactive company with
     * somebody attached to it, which is a state the data quality layer warns about.
     */
    private function lockCompany(Company $company): Company
    {
        /** @var Company|null $fresh */
        $fresh = Company::query()->lockForUpdate()->find($company->id);

        if ($fresh === null) {
            throw new \RuntimeException('La empresa ya no existe.');
        }

        return $fresh;
    }

    /**
     * Lock a catalogue entity and return it, read under that lock.
     *
     * Same shape as the company: an affiliation is a statement about an entity as
     * well as about a person, so the entity's status is part of the decision and has
     * to be read under the same lock that deactivation takes.
     */
    private function lockEntity(SocialSecurityEntity $entity): SocialSecurityEntity
    {
        /** @var SocialSecurityEntity|null $fresh */
        $fresh = SocialSecurityEntity::query()->lockForUpdate()->find($entity->id);

        if ($fresh === null) {
            throw new \RuntimeException('La entidad ya no existe.');
        }

        return $fresh;
    }
}

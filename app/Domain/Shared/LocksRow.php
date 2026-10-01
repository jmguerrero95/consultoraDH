<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Illuminate\Database\Eloquent\Model;

/**
 * Row level locking for the historical records.
 *
 * Every write in A02 that touches a row of history has to take it out of reach of
 * a concurrent request first. A transfer closes one row and opens another; if two
 * operators transferred the same relationship at the same time, both would read
 * it as open and both would open a new one, leaving the client with two open
 * relationships and no transfer having actually moved them.
 *
 * `SELECT ... FOR UPDATE` is what prevents that, and it has to be the row, not the
 * model instance the caller happens to be holding: the caller's copy may have been
 * read before the other transaction started.
 *
 * Used inside a transaction. A lock taken outside one is released immediately,
 * which makes it look like it worked.
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
}

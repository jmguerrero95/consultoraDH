<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions;

use App\Domain\Companies\Events\CompanyStatusChanged;
use App\Domain\Shared\RecordStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Activates or deactivates a company.
 *
 * A company with open relationships cannot be deactivated, and the block is
 * unconditional: unlike a client, there is no sensible automatic answer here. A
 * client can leave the portfolio, but a company that still has people attached to
 * it is simply still in use, and closing everyone's relationships to satisfy a
 * status flag would rewrite the employment history of every one of them.
 *
 * So the operation refuses and says how many people are in the way. Resolving
 * those relationships is a decision a person has to make.
 *
 * ## Where the count happens
 *
 * Inside the transaction, after the company row is locked and re-read. Counting
 * first left the decision stale: a relationship opened by another request in the
 * meantime was invisible here, so the company was deactivated with somebody
 * attached to it, which is exactly the state `inactive_company_with_active_clients`
 * warns about. It is the same mistake the client side had, and it is now the same
 * fix.
 *
 * ## The lock order
 *
 * This operation locks only the company, and it takes the relationship rows under
 * that same lock. Every operation that opens a relationship takes the client, then
 * the destination company, then the history rows; see `LocksRow`. A single lock here
 * cannot deadlock against that order, because this transaction never holds a lock
 * that the other one waits for while holding an earlier one.
 */
final class SetCompanyStatus
{
    public function execute(Company $company, RecordStatus $to, User $actor): Company
    {
        return DB::transaction(function () use ($company, $to, $actor): Company {
            // Lock first, read again, and only then look at the relationships.
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);

            if ($locked->status === $to) {
                return $locked;
            }

            if ($to === RecordStatus::Inactive) {
                // Counted here, not before: the count is part of the decision and the
                // decision is made under the lock.
                //
                // The rows are pinned and then counted rather than counted with
                // `lockForUpdate()` on an aggregate, because PostgreSQL refuses
                // `FOR UPDATE` next to `count()`. Holding the company lock already
                // serialises the relationship inserts that could change this number,
                // since every one of them takes that lock first; pinning the rows as
                // well keeps them from moving underneath this transaction.
                $open = $locked->activeAssignments()
                    ->lockForUpdate()
                    ->get()
                    ->count();

                if ($open > 0) {
                    throw CompanyHasActiveClients::forCompany($locked, $open);
                }
            }

            $from = $locked->status->value;

            $locked->forceFill(['status' => $to->value])->save();

            event(new CompanyStatusChanged($locked->refresh(), $actor, $from, $to->value));

            return $locked->refresh();
        });
    }
}

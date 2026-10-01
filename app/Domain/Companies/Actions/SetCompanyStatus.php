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
 */
final class SetCompanyStatus
{
    public function execute(Company $company, RecordStatus $to, User $actor): Company
    {
        if ($company->status === $to) {
            return $company;
        }

        if ($to === RecordStatus::Inactive) {
            $open = $company->activeAssignments()->count();

            if ($open > 0) {
                throw CompanyHasActiveClients::forCompany($company, $open);
            }
        }

        return DB::transaction(function () use ($company, $to, $actor): Company {
            $from = $company->status->value;

            $company->forceFill(['status' => $to->value])->save();

            event(new CompanyStatusChanged($company->refresh(), $actor, $from, $to->value));

            return $company->refresh();
        });
    }
}

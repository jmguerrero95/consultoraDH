<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Models\ClientCompanyRate;
use Illuminate\Database\Eloquent\Collection;

/**
 * The configured monthly amount for one client and one company in one month.
 *
 * The newest rate whose `effective_month` has arrived is the answer. There is no
 * overlapping interval to compute, because uniqueness is on
 * `(client_id, company_id, effective_month)`: one decision per month, and the
 * sequence of decisions is the history.
 *
 * Nothing is derived. There is no formula here from EPS, AFP, ARL, CCF, a risk
 * level, a salary or a minimum wage, because no such rule was supplied. A missing
 * rate is `missing`, and generation turns that into a blocker rather than
 * inventing a figure, an average, or the previous client's amount.
 */
final class RateResolver
{
    public function resolve(int $clientId, int $companyId, MonthValue $month): ?ClientCompanyRate
    {
        return ClientCompanyRate::query()
            ->where('client_id', $clientId)
            ->where('company_id', $companyId)
            ->forMonth($month)
            ->first();
    }

    /**
     * The amount, or null when no rate had started by that month.
     */
    public function amountFor(int $clientId, int $companyId, MonthValue $month): ?int
    {
        return $this->resolve($clientId, $companyId, $month)?->amount_cop;
    }

    /**
     * Every rate for a pair, newest first, for the history screen.
     *
     * @return Collection<int, ClientCompanyRate>
     */
    public function historyFor(int $clientId, int $companyId): Collection
    {
        return ClientCompanyRate::query()
            ->where('client_id', $clientId)
            ->where('company_id', $companyId)
            ->orderByDesc('effective_month')
            ->orderByDesc('id')
            ->get();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

use App\Domain\Billing\AgingBucket;
use App\Domain\Billing\SettlementState;
use App\Domain\Billing\TrafficLight;
use App\Domain\Clients\DocumentNumber;
use App\Domain\Clients\DocumentType;
use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Models\Client;
use App\Models\MonthlyPeriod;
use App\Models\Payment;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the portfolio owes, and who.
 *
 * ## Everything is computed here
 *
 * Balances, aging, settlement state, the traffic light and the exact list of unpaid
 * months are all server-side. The interface may preview them, but this is the
 * authority: a figure that only exists in Vue is a figure two screens disagree
 * about.
 *
 * ## No per-row loops
 *
 * The screen this serves will eventually be the largest in the application, so the
 * whole answer comes out of aggregate SQL: correlated subqueries for the two sums
 * that make up a balance, and a `GROUP BY` for the totals. A page of 25 clients is a
 * fixed number of queries whether the portfolio holds twenty clients or twenty
 * thousand.
 *
 * The arithmetic in SQL is the same arithmetic `ObligationTotals` performs in PHP,
 * and `ReceivablesParityTest` holds the two implementations against each other. That
 * test is the only reason it is safe to have both: two implementations of one
 * formula is two chances to be wrong, and without the parity check they would drift
 * apart silently the first time somebody corrected one of them.
 */
/**
 * An aggregated list column, as a list.
 *
 * `json_agg` comes back from the driver already decoded, so this only has to cope
 * with the two shapes it can take: the value itself when there were rows, and null
 * when there were none. Casting with `(array)` is not enough — on a Postgres array
 * string it would produce a one element list holding the whole string.
 *
 * @return list<string>
 */
function listFrom(mixed $value): array
{
    if ($value === null || $value === '') {
        return [];
    }

    if (is_array($value)) {
        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }

    if (is_string($value)) {
        return array_values(array_map(
            static fn (string $item): string => trim(trim($item), '"'),
            array_filter(explode(',', trim(trim($value), '{}')), static fn (string $i): bool => $i !== ''),
        ));
    }

    return [(string) $value];
}

final class ReceivablesService
{
    /**
     * One client's account: every figure the statement screen shows.
     *
     * @return array<string, mixed>
     */
    public function clientAccount(Client $client, ?Carbon $asOf = null): array
    {
        $asOf ??= now();

        $obligations = $this->obligationsQuery()
            // On the obligation, not on the joined assignment. The join is a left
            // join because an obligation carries a snapshot of the relationship and
            // must still be reported after the assignment row is closed; filtering on
            // the joined table would silently drop exactly those rows.
            ->where('monthly_obligations.client_id', $client->id)
            ->orderBy('monthly_periods.period_month')
            ->orderBy('monthly_obligations.id')
            ->get();

        // `get()` on a query builder returns plain objects, not arrays.
        $rows = $obligations->map(fn (object $row): array => $this->hydrateRow($row, $asOf))->all();

        $outstanding = array_values(array_filter($rows, fn (array $row): bool => $row['balance_cop'] > 0));
        $owedPeriods = array_map(fn (array $row): string => $row['period_key'], $outstanding);
        sort($owedPeriods);

        $overdueCount = count(array_filter($outstanding, fn (array $row): bool => $row['is_overdue']));

        return [
            'client' => [
                'id' => $client->id,
                'full_name' => $client->fullName(),
                'document_label' => $client->documentLabel(),
            ],
            'as_of' => $asOf->format('Y-m-d'),
            'summary' => [
                'total_effective_obligations_cop' => $this->sum($rows, 'effective_amount_cop'),
                'total_paid_cop' => $this->sum($rows, 'paid_amount_cop'),
                'outstanding_balance_cop' => $this->sum($outstanding, 'balance_cop'),
                'overdue_balance_cop' => $this->sum(
                    array_filter($outstanding, fn (array $row): bool => $row['is_overdue']),
                    'balance_cop',
                ),
                'unallocated_credit_cop' => $this->unallocatedCreditFor($client),
                'open_obligations_count' => count($outstanding),
                'overdue_obligations_count' => $overdueCount,
                // The exact months, not "3 months late". A person asking "which
                // months do you owe me" needs the months; a count cannot be turned
                // back into them.
                'owed_periods' => $owedPeriods,
                'owed_period_count' => count($owedPeriods),
                'traffic_light' => TrafficLight::forOverdueCount($overdueCount)->value,
                'traffic_light_label' => TrafficLight::forOverdueCount($overdueCount)->label(),
                'traffic_light_reason' => TrafficLight::forOverdueCount($overdueCount)->meaning($overdueCount),
            ],
            'obligations' => $rows,
        ];
    }

    /**
     * The receivables screen: a page of clients who owe something.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function list(array $filters, int $page, int $perPage): array
    {
        $asOf = isset($filters['as_of']) && is_string($filters['as_of'])
            ? Carbon::parse($filters['as_of'])->startOfDay()
            : now();

        // The per-obligation figures first, then everything else. Postgres will not
        // let a `where` or a `having` mention a select alias, so the derived figures
        // are wrapped as a derived table: from here down, `balance_cop` and
        // `paid_amount_cop` are ordinary columns of a real relation and can be
        // filtered on, summed and grouped directly.
        $obligations = DB::query()->fromSub($this->obligationsQuery(), 'obligations');

        if (isset($filters['client_id'])) {
            $obligations->where('obligations.client_id', (int) $filters['client_id']);
        }

        if (isset($filters['company_id'])) {
            $obligations->where('obligations.company_id', (int) $filters['company_id']);
        }

        if (isset($filters['period_from'])) {
            $obligations->where('obligations.period_month', '>=', MonthlyPeriod::parse((string) $filters['period_from'])->startsOn());
        }

        if (isset($filters['period_to'])) {
            $obligations->where('obligations.period_month', '<=', MonthlyPeriod::parse((string) $filters['period_to'])->startsOn());
        }

        if (($filters['settlement_state'] ?? null) !== null) {
            $state = SettlementState::from((string) $filters['settlement_state']);

            // The state is derived, so it is filtered after the arithmetic rather than
            // with a column: `settlement_state = 'paid'` is expressible as
            // `balance = 0`, and expressing it as a column would mean storing the
            // derived value this module refuses to store.
            match ($state) {
                SettlementState::Paid => $obligations->where('obligations.balance_cop', 0),
                SettlementState::Pending => $obligations->where('obligations.balance_cop', '>', 0)
                    ->where('obligations.paid_amount_cop', 0),
                SettlementState::Partial => $obligations->where('obligations.balance_cop', '>', 0)
                    ->where('obligations.paid_amount_cop', '>', 0),
            };
        }

        if (($filters['aging_bucket'] ?? null) !== null) {
            $bucket = AgingBucket::from((string) $filters['aging_bucket']);
            $this->applyAgingFilter($obligations, $bucket, $asOf);
        }

        // An overdue obligation is one still owing, past its date. A settled one is
        // not late however long ago it fell due.
        if (($filters['overdue'] ?? null) !== null) {
            $obligations->where('obligations.balance_cop', '>', 0)
                ->where('obligations.due_on', '<', $asOf->format('Y-m-d'));
        }

        if (isset($filters['minimum_balance'])) {
            $obligations->where('obligations.balance_cop', '>=', (int) $filters['minimum_balance']);
        }

        if (isset($filters['maximum_balance'])) {
            $obligations->where('obligations.balance_cop', '<=', (int) $filters['maximum_balance']);
        }

        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            // The needle is lowercased as well as the column. Without that, typing
            // "Ana" into the box would not match "Ana Maria", because the comparison
            // is against `lower(first_names)` and the needle kept its capital.
            $needle = '%'.mb_strtolower(trim((string) $filters['search'])).'%';

            $obligations->where(function (QueryBuilder $query) use ($needle): void {
                $query->whereRaw('lower(obligations.first_names) LIKE ?', [$needle])
                    ->orWhereRaw('lower(obligations.last_names) LIKE ?', [$needle])
                    ->orWhereRaw('obligations.document_number LIKE ?', [$needle]);
            });
        }

        // One query for the totals, so the page header and the rows cannot disagree.
        $summaryRow = (clone $obligations)
            ->selectRaw(
                'count(distinct obligations.client_id) filter (where obligations.balance_cop > 0) as clients_count',
            )
            ->selectRaw('coalesce(sum(obligations.effective_amount_cop), 0) as total_effective_cop')
            ->selectRaw('coalesce(sum(obligations.paid_amount_cop), 0) as total_paid_cop')
            ->selectRaw('coalesce(sum(obligations.balance_cop), 0) as total_balance_cop')
            ->selectRaw('coalesce(sum(CASE WHEN obligations.balance_cop > 0 AND obligations.due_on < ? THEN obligations.balance_cop ELSE 0 END), 0) as total_overdue_cop', [$asOf->format('Y-m-d')])
            ->selectRaw('count(*) filter (where obligations.balance_cop > 0) as open_obligations_count')
            ->selectRaw('count(*) filter (where obligations.balance_cop > 0 AND obligations.due_on < ? ) as overdue_obligations_count', [$asOf->format('Y-m-d')])
            ->first();

        // The header describes the filtered obligations whatever the list shows, so the
        // two cannot answer different questions about the same month.
        $total = (int) ($summaryRow->clients_count ?? 0);

        // The rows, one per client, with their figures aggregated. The per-client
        // pagination is over distinct clients, which is what the screen shows, and it
        // is computed with a grouped query rather than by loading every row and
        // slicing in PHP.
        $perClient = $obligations
            ->selectRaw('obligations.client_id')
            ->selectRaw('obligations.first_names, obligations.last_names, obligations.document_type, obligations.document_number')
            ->selectRaw('coalesce(sum(obligations.balance_cop), 0) as balance_cop')
            ->selectRaw('coalesce(sum(obligations.paid_amount_cop), 0) as paid_amount_cop')
            ->selectRaw('coalesce(sum(CASE WHEN obligations.balance_cop > 0 AND obligations.due_on < ? THEN obligations.balance_cop ELSE 0 END), 0) as overdue_balance_cop', [$asOf->format('Y-m-d')])
            ->selectRaw('count(*) filter (where obligations.balance_cop > 0) as open_obligations_count')
            ->selectRaw('count(*) filter (where obligations.balance_cop > 0 AND obligations.due_on < ?) as overdue_obligations_count', [$asOf->format('Y-m-d')])
            // json_agg rather than array_agg: the driver hands back a Postgres array as the
            // string `{2026-01,2026-02}`, which is not a list of months and would be
            // drawn on the screen as one piece of text.
            ->selectRaw(
                'json_agg(obligations.period_key order by obligations.period_key) as period_keys',
            )
            ->selectRaw('max(obligations.due_on) as oldest_due_on')
            ->selectRaw('json_agg(distinct obligations.company_id) as company_ids')
            ->selectRaw('json_agg(distinct obligations.legal_name) as company_names')
            ->groupBy(
                'obligations.client_id',
                'obligations.first_names',
                'obligations.last_names',
                'obligations.document_type',
                'obligations.document_number',
            )
            ->orderByDesc('balance_cop')
            ->orderBy('obligations.document_number');

        // Only the clients with something outstanding, unless the caller explicitly
        // asks for everybody. Cartera is a list of debtors; a row with a zero balance
        // is not one.
        //
        // Applied to the *aggregate*, after summing, and never to the individual
        // obligations behind it. Filtering first would drop a settled month out of the
        // arithmetic entirely, and the row would then report a smaller collected
        // figure than the client actually paid — which is a receipt, not a projection.
        if (($filters['outstanding_only'] ?? true) === true) {
            $perClient->havingRaw('coalesce(sum(obligations.balance_cop), 0) > 0');
        }

        if (($filters['traffic_light'] ?? null) !== null) {
            $this->applyTrafficLightFilter($perClient, (string) $filters['traffic_light'], $asOf);
        }

        $rows = $perClient
            ->forPage($page, $perPage)
            ->get();

        $items = $rows->map(function (object $row) use ($asOf): array {
            $overdueCount = (int) $row->overdue_obligations_count;
            $light = TrafficLight::forOverdueCount($overdueCount);

            return [
                'client_id' => (int) $row->client_id,
                'full_name' => trim($row->first_names.' '.$row->last_names),
                'document_label' => DocumentNumber::forDisplay(
                    $row->document_number,
                    DocumentType::from($row->document_type),
                ),
                'company_names' => listFrom($row->company_names ?? null),
                'balance_cop' => (int) $row->balance_cop,
                'paid_amount_cop' => (int) $row->paid_amount_cop,
                'overdue_balance_cop' => (int) $row->overdue_balance_cop,
                'open_obligations_count' => (int) $row->open_obligations_count,
                'overdue_obligations_count' => $overdueCount,
                'owed_periods' => listFrom($row->period_keys ?? null),
                'oldest_due_on' => $row->oldest_due_on,
                // From the due date to today, as everywhere else: the row is shown by
                // its oldest debt, and the client's risk is the longest-standing one.
                'aging_bucket' => AgingBucket::forDaysLate(
                    $row->oldest_due_on === null
                        ? 0
                        : (int) Carbon::parse($row->oldest_due_on)->diffInDays($asOf, false)
                )->value,
                'traffic_light' => $light->value,
                'traffic_light_label' => $light->label(),
                'traffic_light_reason' => $light->meaning($overdueCount),
            ];
        })->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'summary' => [
                'outstanding_balance_cop' => (int) $summaryRow->total_balance_cop,
                'overdue_balance_cop' => (int) $summaryRow->total_overdue_cop,
                'total_effective_obligations_cop' => (int) $summaryRow->total_effective_cop,
                'total_paid_cop' => (int) $summaryRow->total_paid_cop,
                'clients_with_debt' => $total,
                'open_obligations_count' => (int) $summaryRow->open_obligations_count,
                'overdue_obligations_count' => (int) $summaryRow->overdue_obligations_count,
                'unallocated_credit_cop' => $this->portfolioUnallocatedCredit(),
                'payments_requiring_reconciliation' => Payment::query()
                    ->whereNull('voided_at')
                    ->whereRaw('amount_cop > ('
                        .'select coalesce(sum(pa.amount_cop), 0) from payment_allocations pa '
                        .'where pa.payment_id = payments.id and pa.reversed_at is null)')
                    ->count(),
            ],
        ];
    }

    /**
     * Every client, for the dashboard metrics.
     *
     * One aggregate query for the whole portfolio. A dashboard that issues a query
     * per client is a dashboard whose cost grows with the portfolio, which is the
     * failure mode this exists to avoid.
     *
     * @return array<string, int>
     */
    public function portfolioSummary(?Carbon $asOf = null): array
    {
        $asOf ??= now();

        $row = DB::query()->fromSub($this->obligationsQuery(), 'obligations')
            ->selectRaw('coalesce(sum(obligations.effective_amount_cop), 0) as total_effective_cop')
            ->selectRaw('coalesce(sum(obligations.paid_amount_cop), 0) as total_paid_cop')
            ->selectRaw('coalesce(sum(obligations.balance_cop), 0) as total_balance_cop')
            ->selectRaw('coalesce(sum(CASE WHEN obligations.balance_cop > 0 AND obligations.due_on < ? THEN obligations.balance_cop ELSE 0 END), 0) as total_overdue_cop', [$asOf->format('Y-m-d')])
            ->selectRaw('count(distinct obligations.client_id) filter (where obligations.balance_cop > 0) as clients_with_debt')
            ->selectRaw('count(*) filter (where obligations.balance_cop > 0) as open_obligations_count')
            ->selectRaw('count(*) filter (where obligations.balance_cop > 0 AND obligations.due_on < ?) as overdue_obligations_count', [$asOf->format('Y-m-d')])
            ->first();

        return [
            'total_effective_obligations_cop' => (int) $row->total_effective_cop,
            'total_paid_cop' => (int) $row->total_paid_cop,
            'outstanding_balance_cop' => (int) $row->total_balance_cop,
            'overdue_balance_cop' => (int) $row->total_overdue_cop,
            'clients_with_debt' => (int) $row->clients_with_debt,
            'open_obligations_count' => (int) $row->open_obligations_count,
            'overdue_obligations_count' => (int) $row->overdue_obligations_count,
            'unallocated_credit_cop' => $this->portfolioUnallocatedCredit(),
            'payments_requiring_reconciliation' => Payment::query()
                ->whereNull('voided_at')
                ->whereRaw('amount_cop > ('
                    .'select coalesce(sum(pa.amount_cop), 0) from payment_allocations pa '
                    .'where pa.payment_id = payments.id and pa.reversed_at is null)')
                ->count(),
        ];
    }

    // --- internals ------------------------------------------------------

    /**
     * The obligation query with the two derived sums attached.
     *
     * `effective_amount_cop` and `paid_amount_cop` are correlated subqueries rather
     * than joins, because a join would multiply the rows: an obligation with three
     * adjustments and two allocations would appear six times and every total would
     * be wrong. Each sum is a scalar, so each row is one obligation.
     *
     * `paid_amount_cop` excludes voided payments, which is what makes a void take
     * effect immediately in every balance derived from this query.
     */
    private function obligationsQuery(): QueryBuilder
    {
        return DB::table('monthly_obligations')
            ->join('monthly_periods', 'monthly_periods.id', '=', 'monthly_obligations.period_id')
            ->join('clients', 'clients.id', '=', 'monthly_obligations.client_id')
            ->join('companies', 'companies.id', '=', 'monthly_obligations.company_id')
            ->leftJoin('client_company_assignments', 'client_company_assignments.id', '=', 'monthly_obligations.client_company_assignment_id')
            ->select('monthly_obligations.*')
            ->selectRaw('date_trunc(\'month\', monthly_periods.period_month)::date as period_month')
            ->selectRaw('to_char(monthly_periods.period_month, \'YYYY-MM\') as period_key')
            ->selectRaw('clients.first_names, clients.last_names, clients.document_type, clients.document_number')
            ->selectRaw('companies.legal_name')
            ->selectRaw('(monthly_obligations.base_amount_cop + coalesce(('
                .'select sum(oa.delta_cop) from obligation_adjustments oa '
                .'where oa.obligation_id = monthly_obligations.id'
                .'), 0)) as effective_amount_cop')
            ->selectRaw('coalesce(('
                .'select sum(pa.amount_cop) from payment_allocations pa '
                .'inner join payments p on p.id = pa.payment_id '
                .'where pa.obligation_id = monthly_obligations.id '
                .'and pa.reversed_at is null and p.voided_at is null'
                .'), 0) as paid_amount_cop')
            // The balance spelled out rather than derived from the two columns beside
            // it. Postgres will not let a `having` or a `where` refer to a select
            // alias, and every filter on this screen needs the balance, so it is
            // written once here and the query above it becomes a derived table where
            // this name is a real column.
            ->selectRaw('((monthly_obligations.base_amount_cop + coalesce(('
                .'select sum(oa.delta_cop) from obligation_adjustments oa '
                .'where oa.obligation_id = monthly_obligations.id'
                .'), 0)) - coalesce(('
                .'select sum(pa2.amount_cop) from payment_allocations pa2 '
                .'inner join payments p2 on p2.id = pa2.payment_id '
                .'where pa2.obligation_id = monthly_obligations.id '
                .'and pa2.reversed_at is null and p2.voided_at is null'
                .'), 0)) as balance_cop');
    }

    /**
     * Turn a raw query row into the shape the interface reads.
     *
     * @param  object<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function hydrateRow(object $row, Carbon $asOf): array
    {
        $base = (int) $row->base_amount_cop;
        $effective = (int) $row->effective_amount_cop;
        $paid = (int) $row->paid_amount_cop;
        $balance = $effective - $paid;

        $dueOn = Carbon::parse((string) $row->due_on)->startOfDay();

        // Measured from the due date to today, not the other way round. With the
        // arguments the other way round, an obligation that is not due until next
        // month comes out as thirty days late, and the whole aging report inverts.
        $daysLate = (int) $dueOn->diffInDays($asOf, false);

        $bucket = $balance > 0 ? AgingBucket::forDaysLate($daysLate) : AgingBucket::NotDue;

        return [
            'id' => (int) $row->id,
            'period_id' => (int) $row->period_id,
            'period_key' => (string) $row->period_key,
            'period_label' => MonthValue::fromKey((string) $row->period_key)->label(),
            'client_id' => (int) $row->client_id,
            'company_id' => (int) $row->company_id,
            'company_name' => $row->legal_name,
            'base_amount_cop' => $base,
            'effective_amount_cop' => $effective,
            'paid_amount_cop' => $paid,
            'balance_cop' => $balance,
            'due_on' => $dueOn->format('Y-m-d'),
            'settlement_state' => SettlementState::for($effective, $paid)->value,
            'settlement_state_label' => SettlementState::for($effective, $paid)->label(),
            // Strictly greater: an obligation due today is not yet overdue. The day
            // it falls due is the day it is still payable.
            'is_overdue' => $balance > 0 && $asOf->gt($dueOn),
            'days_late' => $balance > 0 ? max(0, $daysLate) : 0,
            'aging_bucket' => $bucket->value,
            'aging_bucket_label' => $bucket->label(),
        ];
    }

    private function unallocatedCreditFor(Client $client): int
    {
        return (int) Payment::query()
            ->where('client_id', $client->id)
            ->whereNull('voided_at')
            ->selectRaw('coalesce(sum(payments.amount_cop - coalesce(('
                .'select sum(pa.amount_cop) from payment_allocations pa '
                .'where pa.payment_id = payments.id and pa.reversed_at is null'
                .'), 0)), 0) as credit')
            ->value('credit');
    }

    private function portfolioUnallocatedCredit(): int
    {
        return (int) Payment::query()
            ->whereNull('voided_at')
            ->selectRaw('coalesce(sum(payments.amount_cop - coalesce(('
                .'select sum(pa.amount_cop) from payment_allocations pa '
                .'where pa.payment_id = payments.id and pa.reversed_at is null'
                .'), 0)), 0) as credit')
            ->value('credit');
    }

    private function applyAgingFilter(QueryBuilder $query, AgingBucket $bucket, Carbon $asOf): void
    {
        // Only a balance still owed is aged. A debt that has been paid is not late,
        // however long ago it fell due, and filtering on the bucket before the balance
        // test would count it.
        $query->where('obligations.balance_cop', '>', 0);

        match ($bucket) {
            AgingBucket::NotDue => $query->whereDate('obligations.due_on', '>=', $asOf->toDateString()),
            AgingBucket::OneToThirty => $query->whereDate('obligations.due_on', '<', $asOf->toDateString())
                ->whereDate('obligations.due_on', '>=', $asOf->copy()->subDays(30)->toDateString()),
            AgingBucket::ThirtyOneToSixty => $query->whereDate('obligations.due_on', '<', $asOf->copy()->subDays(30)->toDateString())
                ->whereDate('obligations.due_on', '>=', $asOf->copy()->subDays(60)->toDateString()),
            AgingBucket::SixtyOneToNinety => $query->whereDate('obligations.due_on', '<', $asOf->copy()->subDays(60)->toDateString())
                ->whereDate('obligations.due_on', '>=', $asOf->copy()->subDays(90)->toDateString()),
            AgingBucket::OverNinety => $query->whereDate('obligations.due_on', '<', $asOf->copy()->subDays(90)->toDateString()),
        };
    }

    /**
     * Filter by semaphore.
     *
     * Applied over the grouped per-client query rather than as a `where`, because
     * the light is derived from a count of overdue rows and does not exist as a
     * column to filter on.
     */
    private function applyTrafficLightFilter(QueryBuilder $query, string $light, Carbon $asOf): void
    {
        // The date is passed in, not read out of the query's bindings. Reading it from
        // there would use whichever placeholder happened to be bound first, which is
        // whichever filter the caller supplied: the screen would count overdue rows
        // against a date chosen by the sort order.
        $overdue = 'count(*) filter (where obligations.balance_cop > 0 and obligations.due_on < ?)';
        $date = $asOf->format('Y-m-d');

        match ($light) {
            TrafficLight::Green->value => $query->havingRaw("{$overdue} = 0", [$date]),
            TrafficLight::Yellow->value => $query->havingRaw("{$overdue} = 1", [$date]),
            TrafficLight::Orange->value => $query->havingRaw("{$overdue} = 2", [$date]),
            default => $query->havingRaw("{$overdue} >= 3", [$date]),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sum(array $rows, string $key): int
    {
        return (int) array_sum(array_map(fn (array $row): int => (int) $row[$key], $rows));
    }
}

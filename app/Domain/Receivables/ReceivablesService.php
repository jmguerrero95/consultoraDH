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
use App\Models\Payment;
use App\Support\Validation\SafeSearch;
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
 * Three shapes have to be coped with, and A03-R1 added the third:
 *
 *   * **decoded** — `json_agg` without a `FILTER` comes back from the driver as a PHP
 *     array, when there were rows.
 *   * **null** — the `FILTER (WHERE ...)` matched nothing. "No company is owed" is an empty
 *     list, never a list containing null.
 *   * **a JSON string** — `json_agg` **with** a `FILTER` comes back as the text
 *     `["2026-02"]` rather than as an array, because the filter changes the aggregate's type
 *     as far as the driver is concerned. Decoding it as a Postgres array string instead
 *     produced the single element `["2026-02"]`, which the interface then rendered as the
 *     literal text of a month.
 *
 * `(array)` casting is not enough for any of them.
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
        // JSON first: it is unambiguous, and a month key never needs unescaping.
        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return array_values(array_map(static fn (mixed $item): string => (string) $item, $decoded));
        }

        // Then the Postgres array literal, `{a,b}`, for the plain `array_agg` form.
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
        // §25. Every path folds to a calendar day. Comparing a debt due at midnight today
        // against `now()` at 11:00 called it overdue during its own due date, while the SQL
        // comparisons in the same service — which see dates, not instants — said it was not.
        $asOf = $this->agingReference($asOf);

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

        // §22. Distinct, and only for money still owed. The account screen and the cartera
        // list have to agree: the list already answered "which months are owed" this way,
        // and the account was listing every month the client ever had, paid ones included.
        $owedPeriods = array_values(array_unique(array_map(
            fn (array $row): string => $row['period_key'],
            $outstanding,
        )));
        sort($owedPeriods);

        // §24. Distinct late months, to match the cartera list and the domain language.
        $overduePeriods = array_values(array_unique(array_map(
            fn (array $row): string => $row['period_key'],
            array_filter($outstanding, fn (array $row): bool => $row['is_overdue']),
        )));

        // §24, and §2 of R3. Two different questions, two different numbers — and until
        // now one number published under both names:
        //
        //   overdue_obligations_count  how many DEBTS are late  → count the rows
        //   overdue_periods_count      how many MONTHS are late → count the distinct keys
        //
        // March, one client owing two employers: two obligations are late and one month is.
        // `overdue_obligations_count` was handed the month count, so a card labelled
        // "obligaciones" said 1 where there were 2. Both figures were the same number, so
        // the name and the number it carried could not both be right, and the "obligaciones"
        // name is the one a collections operator reads as a count of debts.
        //
        // Not cosmetic here: this is the screen where somebody decides whether to call one
        // person about one problem or two.
        $overdueObligations = array_values(array_filter(
            $outstanding,
            fn (array $row): bool => $row['is_overdue'],
        ));

        $overduePeriodCount = count($overduePeriods);

        // The semaphore answers "how many months are late" and stays on the month count. A
        // client owing two employers in the same March is one month late; counting rows
        // called that two and moved a yellow client to orange. That is R1 §24, unchanged
        // here — what was wrong were the names of the two counts around it.
        $trafficLight = TrafficLight::forOverdueCount($overduePeriodCount);

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
                'overdue_balance_cop' => $this->sum($overdueObligations, 'balance_cop'),
                'unallocated_credit_cop' => $this->unallocatedCreditFor($client),
                'open_obligations_count' => count($outstanding),
                // Rows, scoped to money still owed — the same scope the cartera list uses, so
                // the two screens cannot report different figures for the same client.
                'overdue_obligations_count' => count($overdueObligations),
                'overdue_periods_count' => $overduePeriodCount,
                // The exact months, not "3 months late". A person asking "which
                // months do you owe me" needs the months; a count cannot be turned
                // back into them.
                'owed_periods' => $owedPeriods,
                'owed_period_count' => count($owedPeriods),
                'traffic_light' => $trafficLight->value,
                'traffic_light_label' => $trafficLight->label(),
                'traffic_light_reason' => $trafficLight->meaning($overduePeriodCount),
            ],
            'obligations' => $rows,
        ];
    }

    /**
     * The receivables screen: a page of clients who owe something.
     *
     * ## A row is a CLIENT, and the filters agree about that
     *
     * Every filter here is either about a client or is applied to the **client's aggregate**,
     * because a cartera row is one client and `total`, `last_page` and the summary have to
     * describe the same population as the rows do. Three corrections follow from that one
     * sentence, and all three were wrong before A03-R1:
     *
     *   * `minimum_balance` / `maximum_balance` filtered individual obligations. A client
     *     owing 600000 in January and 600000 in February has 1200000 outstanding; asking for
     *     `minimum_balance=1000000` excluded them, because no *single* obligation reached
     *     the figure. They are now compared against the sum the row displays.
     *   * `traffic_light` was applied **after** `total` had been calculated, so the header
     *     counted every debtor while the table showed a subset — including on the last page,
     *     where the pager claimed pages that did not exist.
     *   * `outstanding_only` defaulted to true while `settlement_state=paid` filtered for
     *     settled obligations. The two contradict each other, so asking for paid
     *     obligations always produced an empty screen and no explanation.
     *
     * ## The filters that belong to a client row
     *
     * `outstanding_only=false` means "include clients whose balance is zero", and when the
     * caller asks for that, `total` counts the same rows. `settlement_state` is different:
     * it names a *state of an obligation*, so asking for `paid` **implies** that the caller
     * is not asking for a debtor list. Rather than refuse the combination, the default is
     * dropped when a settlement state is present, because a filter that is silently
     * contradictory is a filter that looks broken.
     *
     * ## Debt-specific figures come only from money still owed
     *
     * `owed_periods`, the company list and the oldest due date are derived with
     * `FILTER (WHERE balance_cop > 0)`. Before A03-R1 they were aggregates over **every**
     * obligation behind the client, so a January debt that had been paid in full still
     * appeared in "owed periods", and an old company's name stayed in the "Empresas" column
     * after the client had moved. Both told a collections operator the wrong thing about
     * where the money is.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function list(array $filters, int $page, int $perPage): array
    {
        $asOf = $this->agingReference($filters['as_of'] ?? null);

        // The per-obligation figures first, then everything else. Postgres will not let a
        // `where` or a `having` mention a select alias, so the derived figures are wrapped
        // as a derived table: from here down, `balance_cop` and `paid_amount_cop` are
        // ordinary columns of a real relation.
        $obligations = DB::query()->fromSub($this->obligationsQuery(), 'obligations');

        foreach (['client_id', 'company_id'] as $key) {
            if (isset($filters[$key])) {
                $obligations->where('obligations.'.$key, (int) $filters[$key]);
            }
        }

        // `MonthValue`, not the Eloquent model of the same short name: `parse()` lives on
        // the domain value object, and asking the model for it was a 500 — so the period
        // range filter had never worked, on either boundary.
        if (isset($filters['period_from'])) {
            $obligations->where(
                'obligations.period_month',
                '>=',
                MonthValue::parse((string) $filters['period_from'])->startsOn(),
            );
        }

        if (isset($filters['period_to'])) {
            $obligations->where(
                'obligations.period_month',
                '<=',
                MonthValue::parse((string) $filters['period_to'])->startsOn(),
            );
        }

        if (($filters['settlement_state'] ?? null) !== null) {
            $state = SettlementState::tryFrom((string) $filters['settlement_state']);

            if ($state !== null) {
                // The state is derived, so it is filtered after the arithmetic rather than
                // with a column: `settlement_state = 'paid'` is expressible as
                // `balance = 0`.
                match ($state) {
                    SettlementState::Paid => $obligations->where('obligations.balance_cop', 0),
                    SettlementState::Pending => $obligations->where('obligations.balance_cop', '>', 0)
                        ->where('obligations.paid_amount_cop', 0),
                    SettlementState::Partial => $obligations->where('obligations.balance_cop', '>', 0)
                        ->where('obligations.paid_amount_cop', '>', 0),
                };
            }
        }

        if (($filters['aging_bucket'] ?? null) !== null) {
            $bucket = AgingBucket::tryFrom((string) $filters['aging_bucket']);

            if ($bucket !== null) {
                $this->applyAgingFilter($obligations, $bucket, $asOf);
            }
        }

        // §21. Only a **true** narrows. Absent and `false` are the same question.
        if (($filters['overdue'] ?? false) === true) {
            $obligations->where('obligations.balance_cop', '>', 0)
                ->where('obligations.due_on', '<', $asOf->format('Y-m-d'));
        }

        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            // Escaped and lowercased on both sides: `likeNeedle()` folds the needle and
            // escapes the wildcards, and the columns are folded in SQL. Without the fold,
            // typing "Ana" would not match "Ana María".
            $needle = SafeSearch::likeNeedle($filters['search']);

            $obligations->where(function (QueryBuilder $query) use ($needle): void {
                $query->whereRaw(SafeSearch::match('obligations.first_names'), [$needle])
                    ->orWhereRaw(SafeSearch::match('obligations.last_names'), [$needle])
                    ->orWhereRaw(SafeSearch::match('obligations.document_number'), [$needle])
                    // And the whole name, which no single column contains. Searching a
                    // portfolio by the name on it is the ordinary thing to do.
                    ->orWhereRaw(
                        SafeSearch::fullNameMatch('obligations.first_names', 'obligations.last_names'),
                        [$needle],
                    );
            });
        }

        $perClient = $this->groupedClients($obligations, $asOf);

        // §27 A. The debtor-only default is dropped when the caller asks about settlement
        // state, because `paid` and "must have a balance" cannot both hold. Asking for
        // paid obligations without this produced an empty screen.
        $outstandingOnly = (bool) ($filters['outstanding_only'] ?? true);

        if (isset($filters['settlement_state']) && $filters['settlement_state'] === SettlementState::Paid->value) {
            $outstandingOnly = false;
        }

        if ($outstandingOnly) {
            $perClient->havingRaw('coalesce(sum(obligations.balance_cop), 0) > 0');
        }

        // §27 B. Compared against the aggregate the row displays, not against single
        // obligations. A client owing 600000 twice has 1200000 outstanding and belongs in a
        // `minimum_balance=1000000` result.
        if (isset($filters['minimum_balance'])) {
            $perClient->havingRaw('coalesce(sum(obligations.balance_cop), 0) >= ?', [(int) $filters['minimum_balance']]);
        }

        if (isset($filters['maximum_balance'])) {
            $perClient->havingRaw('coalesce(sum(obligations.balance_cop), 0) <= ?', [(int) $filters['maximum_balance']]);
        }

        if (($filters['traffic_light'] ?? null) !== null) {
            $light = TrafficLight::tryFrom((string) $filters['traffic_light']);

            if ($light !== null) {
                $this->applyTrafficLightFilter($perClient, $light, $asOf);
            }
        }

        // The whole population, before any page is taken off it. §1: the header summary
        // describes this population and not the page, so it must be captured before
        // `forPage()` puts a `limit` on the builder — a summary computed from the page
        // would answer a different question from the one the pager answers.
        $population = clone $perClient;

        // §27 C and D. `total` counts the rows **after** every filter, including the ones
        // applied as `having` on the grouped query. Computed from the same grouped query, so
        // the header and the pager cannot disagree — which is what made the last page of a
        // traffic-light filter claim rows that were not there.
        // Counting the grouped query as a subquery rather than adding a `count(*)` to it:
        // the query already has a select list and a `group by`, and appending to that list
        // would return one row **per client** whose first column is that client's name — so
        // `value()` would report a character count, which is where a total of 18 came from
        // when there were 5 debtors.
        $total = (int) DB::query()
            ->fromSub((clone $perClient), 'debtors')
            ->count();

        $rows = $perClient
            ->forPage($page, $perPage)
            ->get();

        $items = $rows->map(function (object $row) use ($asOf): array {
            return $this->describeDebtor($row, $asOf);
        })->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            // §1. The summary describes **the question being asked**, which is the same
            // filtered population the rows and the pager describe.
            //
            // It used to be `portfolioSummary($asOf)`, an independent statement about every
            // debtor in the system. So typing a name, choosing a semaphore band or ticking
            // "only overdue" changed the table while the cards above it kept describing
            // the whole portfolio — three cards and a count sitting on screen next to a
            // list they did not describe, on the one screen whose whole purpose is to
            // describe this list.
            //
            // Derived from the same grouped query as the rows, so the three cannot drift
            // apart again: a filter that moved a row moves every figure with it.
            'summary' => $this->filteredSummary($population),
            'applied_filters' => [
                // Published so the screen can state which question it is answering. A header
                // card that does not follow the filter is worse than no card.
                'overdue' => (bool) ($filters['overdue'] ?? false),
                'outstanding_only' => $outstandingOnly,
                'traffic_light' => $filters['traffic_light'] ?? null,
                'settlement_state' => $filters['settlement_state'] ?? null,
                'aging_bucket' => $filters['aging_bucket'] ?? null,
                'as_of' => $asOf->format('Y-m-d'),
            ],
        ];
    }

    /**
     * One row per client, with every figure the screen shows.
     *
     * Separate from `list()` so the grouping and its columns are written once: the totals,
     * the traffic-light filter, the balance filters and the page all read the same derived
     * table, which is what stops them answering different questions.
     */
    private function groupedClients(QueryBuilder $obligations, Carbon $asOf): QueryBuilder
    {
        $outstanding = 'obligations.balance_cop > 0';

        return $obligations
            ->selectRaw('obligations.client_id')
            ->selectRaw('obligations.first_names, obligations.last_names, obligations.document_type, obligations.document_number')
            ->selectRaw('coalesce(sum(obligations.effective_amount_cop), 0) as effective_amount_cop')
            ->selectRaw('coalesce(sum(obligations.balance_cop), 0) as balance_cop')
            ->selectRaw('coalesce(sum(obligations.paid_amount_cop), 0) as paid_amount_cop')
            ->selectRaw('coalesce(sum(CASE WHEN '.$outstanding.' AND obligations.due_on < ? THEN obligations.balance_cop ELSE 0 END), 0) as overdue_balance_cop', [$asOf->format('Y-m-d')])
            ->selectRaw('count(*) filter (where '.$outstanding.') as open_obligations_count')
            ->selectRaw('count(*) filter (where '.$outstanding.' and obligations.due_on < ?) as overdue_obligations_count', [$asOf->format('Y-m-d')])
            // §24. The semaphore answers "how many **months** are late", so it counts
            // distinct periods and not rows. A client owing two companies in the same March
            // is one month late, and counting rows called that two, which moved a yellow
            // client to orange.
            ->selectRaw(
                'count(distinct obligations.period_key) filter (where '.$outstanding
                .' and obligations.due_on < ?) as overdue_periods_count',
                [$asOf->format('Y-m-d')],
            )
            // §22. Only periods with money still owed. A paid January is not an owed month,
            // and listing it made a collections operator chase a debt that was settled.
            ->selectRaw(
                'json_agg(distinct obligations.period_key order by obligations.period_key) '
                ."filter (where {$outstanding}) as owed_period_keys",
            )
            // §23. The **earliest** outstanding due date. This used to be `max(due_on)`, the
            // newest date, published under the name `oldest_due_on` and rendered as
            // "desde <date>": a client three months late was shown the date of their most
            // recent month.
            ->selectRaw('min(obligations.due_on) filter (where '.$outstanding.') as oldest_due_on')
            // Companies and names only for outstanding debt, for the same reason: an old
            // employer whose obligation was paid must not appear to be one they still owe.
            ->selectRaw(
                'json_agg(distinct obligations.company_id) filter (where '.$outstanding.') as company_ids',
            )
            // §6, the same display name as the row. The `order by` repeats the expression
            // because Postgres orders by the aggregate's *argument*; leaving it on
            // `legal_name` would have sorted the column by a name the screen never shows.
            ->selectRaw(
                'json_agg(distinct obligations.company_display_name order by obligations.company_display_name) '
                ."filter (where {$outstanding}) as company_names",
            )
            ->groupBy(
                'obligations.client_id',
                'obligations.first_names',
                'obligations.last_names',
                'obligations.document_type',
                'obligations.document_number',
            );
    }

    /**
     * One grouped row as the interface reads it.
     *
     * @param  object<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function describeDebtor(object $row, Carbon $asOf): array
    {
        // §24: the light is a function of distinct late **periods**.
        $overduePeriods = (int) ($row->overdue_periods_count ?? 0);
        $light = TrafficLight::forOverdueCount($overduePeriods);

        $oldestDueOn = $row->oldest_due_on;

        return [
            'client_id' => (int) $row->client_id,
            'full_name' => trim($row->first_names.' '.$row->last_names),
            'document_label' => DocumentNumber::forDisplay(
                $row->document_number,
                DocumentType::from($row->document_type),
            ),
            'company_ids' => array_map('intval', listFrom($row->company_ids ?? null)),
            'company_names' => listFrom($row->company_names ?? null),
            'balance_cop' => (int) $row->balance_cop,
            'paid_amount_cop' => (int) $row->paid_amount_cop,
            'overdue_balance_cop' => (int) $row->overdue_balance_cop,
            'open_obligations_count' => (int) $row->open_obligations_count,
            // Named as it is: the count of rows, kept because it is useful, and no longer
            // what the semaphore is built from.
            'overdue_obligations_count' => (int) ($row->overdue_obligations_count ?? 0),
            'overdue_periods_count' => $overduePeriods,
            'owed_periods' => listFrom($row->owed_period_keys ?? null),
            'oldest_due_on' => $oldestDueOn,
            'aging_bucket' => AgingBucket::forDaysLate(
                $oldestDueOn === null
                    ? 0
                    : (int) Carbon::parse($oldestDueOn)->startOfDay()->diffInDays($asOf, false),
            )->value,
            'traffic_light' => $light->value,
            'traffic_light_label' => $light->label(),
            // The reason names **months**, which is what the light counts and what the label
            // in `TrafficLight` already says.
            'traffic_light_reason' => $light->meaning($overduePeriods),
        ];
    }

    /**
     * The header figures, for the population the rows describe.
     *
     * ## The contract
     *
     * A card that does not follow the filter is worse than no card. This used to be
     * `portfolioSummary()` — an independent statement about the whole portfolio — so
     * `search=Ana&traffic_light=yellow&overdue=true` changed the table and left the cards
     * describing everybody. This method takes the **same grouped query** the rows and the
     * pager come from, so the three cannot disagree:
     *
     *   * the debt figures are sums over the filtered rows;
     *   * `clients_with_debt` counts the filtered rows that still owe something, which is
     *     the same population `total` describes;
     *   * the payment figures are scoped to *the clients that population contains*, because
     *     "how much money are they holding in advance" is a question about people, not
     *     about debts. Reporting the whole portfolio's unapplied credit beside a filtered
     *     list would be the same mistake one card lower.
     *
     * ## Why it is two queries and not one
     *
     * The debt figures come from the filtered obligations; the payment figures come from
     * `payments`, a different table with no row-level relationship to the filter. Joining
     * them would multiply every obligation by every payment and make every sum wrong — the
     * reason `obligationsQuery()` uses correlated subqueries in the first place. So the
     * client population is handed over as a subquery, which is bounded: no loop over
     * clients, no N+1, and two queries whatever the portfolio's size.
     *
     * @param  QueryBuilder  $perClient  the grouped, filtered query, before pagination
     * @return array<string, int>
     */
    private function filteredSummary(QueryBuilder $perClient): array
    {
        $row = DB::query()->fromSub((clone $perClient), 'debtors')
            ->selectRaw('coalesce(sum(debtors.effective_amount_cop), 0) as total_effective_cop')
            ->selectRaw('coalesce(sum(debtors.paid_amount_cop), 0) as total_paid_cop')
            ->selectRaw('coalesce(sum(debtors.balance_cop), 0) as outstanding_balance_cop')
            ->selectRaw('coalesce(sum(debtors.overdue_balance_cop), 0) as overdue_balance_cop')
            ->selectRaw('coalesce(sum(debtors.open_obligations_count), 0) as open_obligations_count')
            ->selectRaw('coalesce(sum(debtors.overdue_obligations_count), 0) as overdue_obligations_count')
            // §24: the same row count the light is not built from. Published under its own
            // name precisely because it is a different number from the months.
            ->selectRaw('coalesce(sum(debtors.overdue_periods_count), 0) as overdue_periods_count')
            ->selectRaw('count(*) filter (where debtors.balance_cop > 0) as clients_with_debt')
            ->selectRaw('count(*) as clients_in_result')
            ->first();

        $money = $this->filteredPaymentFigures($perClient);

        return [
            'total_effective_obligations_cop' => (int) $row->total_effective_cop,
            // `applied` — money matched to an obligation. Not money received. §40: the
            // dashboard labelled this "Recaudado", and a client who paid 300000 with
            // nothing allocated read as "collected: 0" on the day the money arrived.
            'total_applied_cop' => (int) $row->total_paid_cop,
            'total_paid_cop' => (int) $row->total_paid_cop,
            'outstanding_balance_cop' => (int) $row->outstanding_balance_cop,
            'overdue_balance_cop' => (int) $row->overdue_balance_cop,
            // A client can appear with a zero balance when the caller asked to see settled
            // clients, so this is "clients in the result" rather than "clients with debt".
            'clients_with_debt' => (int) $row->clients_with_debt,
            'clients_in_result' => (int) $row->clients_in_result,
            'open_obligations_count' => (int) $row->open_obligations_count,
            'overdue_obligations_count' => (int) $row->overdue_obligations_count,
            'overdue_periods_count' => (int) $row->overdue_periods_count,
            // Received, and the part of it not yet applied. Together with the applied figure
            // these satisfy the conservation identity over non-voided payments:
            //
            //     received = applied + unallocated
            //
            // a voided payment is in neither, because money that never arrived is not
            // collected and not unapplied.
            'total_received_cop' => $money['received'],
            'unallocated_credit_cop' => $money['credit'],
            'payments_requiring_reconciliation' => $money['unreconciled'],
        ];
    }

    /**
     * Received, unapplied and unreconciled, for the clients the filtered result contains.
     *
     * One query, with the client population handed in as a subquery of the filtered grouped
     * query rather than assembled in PHP: a loop over clients is the failure mode this whole
     * screen was built to avoid, and it would be reintroduced here by convenience.
     *
     * @return array{received: int, credit: int, unreconciled: int}
     */
    private function filteredPaymentFigures(QueryBuilder $perClient): array
    {
        $applied = 'coalesce((select sum(pa.amount_cop) from payment_allocations pa '
            .'where pa.payment_id = payments.id and pa.reversed_at is null), 0)';

        $row = DB::query()
            ->fromSub(
                (clone $perClient)->select('obligations.client_id as client_id'),
                'population',
            )
            ->join('payments', 'payments.client_id', '=', 'population.client_id')
            ->whereNull('payments.voided_at')
            ->selectRaw('coalesce(sum(payments.amount_cop), 0) as received')
            ->selectRaw('coalesce(sum(payments.amount_cop - ('.$applied.')), 0) as credit')
            ->selectRaw('count(*) filter (where payments.amount_cop > ('.$applied.')) as unreconciled')
            ->first();

        return [
            'received' => (int) $row->received,
            'credit' => (int) $row->credit,
            'unreconciled' => (int) $row->unreconciled,
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
        $asOf = $this->agingReference($asOf);

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
            // `applied` — money that has been matched to an obligation. **Not** money
            // received. §40: the dashboard labelled this figure "Recaudado", and a client
            // who paid 300000 with nothing allocated read as "collected: 0" on the day the
            // money arrived, which is the opposite of what happened.
            'total_applied_cop' => (int) $row->total_paid_cop,
            'total_paid_cop' => (int) $row->total_paid_cop,
            'outstanding_balance_cop' => (int) $row->total_balance_cop,
            'overdue_balance_cop' => (int) $row->total_overdue_cop,
            'clients_with_debt' => (int) $row->clients_with_debt,
            'open_obligations_count' => (int) $row->open_obligations_count,
            'overdue_obligations_count' => (int) $row->overdue_obligations_count,
            // §40. Received, and the part of it not yet applied. Together with the applied
            // figure these satisfy the conservation identity
            //
            //     received = applied + unallocated
            //
            // over non-voided payments, and a voided payment is excluded from both, because
            // money that never arrived is not collected and not unapplied.
            'total_received_cop' => $this->portfolioReceived(),
            'unallocated_credit_cop' => $this->portfolioUnallocatedCredit(),
            'payments_requiring_reconciliation' => Payment::query()
                ->whereNull('voided_at')
                ->whereRaw('amount_cop > ('
                    .'select coalesce(sum(pa.amount_cop), 0) from payment_allocations pa '
                    .'where pa.payment_id = payments.id and pa.reversed_at is null)')
                ->count(),
        ];
    }

    /**
     * Money actually received: the sum of every payment that has not been voided.
     *
     * Deliberately simple. It is the top line of the ledger, not an allocation-aware figure,
     * because "how much came in" is a question about payments and not about obligations. The
     * applied figure is derived from allocations elsewhere, and the difference between the two
     * is the credit a client is holding.
     */
    private function portfolioReceived(): int
    {
        return (int) Payment::query()
            ->whereNull('voided_at')
            ->sum('amount_cop');
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
    /**
     * `Company::displayName()`, written in SQL. See `obligationsQuery()`.
     */
    private const COMPANY_DISPLAY_NAME = "coalesce(nullif(btrim(companies.trade_name), ''), companies.legal_name)";

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
            // §6. The employer's **display** name, which is the trade name when it has one.
            // This read `companies.legal_name` while every other screen — the period's
            // obligations, the billing configuration, the company pickers, the client's own
            // company list — goes through `Company::displayName()`. One employer therefore
            // appeared under two names depending on which screen you were looking at, and a
            // collections operator comparing the portfolio with a client's statement saw the
            // same company renamed between them.
            //
            // Spelled in SQL because this query is the aggregate's source and the aggregate
            // cannot call a PHP method. The expression is `Company::displayName()` exactly:
            // a blank trade name falls back to the legal one, rather than showing an empty
            // "Empresa" cell.
            ->selectRaw(self::COMPANY_DISPLAY_NAME.' as company_display_name')
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
        // Already a calendar day from `agingReference()`; repeated here so this method is
        // safe to call from anywhere without depending on its caller.
        $asOf = $asOf->copy()->startOfDay();

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
            'company_name' => $row->company_display_name,
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
    private function applyTrafficLightFilter(QueryBuilder $query, TrafficLight $light, Carbon $asOf): void
    {
        // The date is passed in, not read out of the query's bindings. Reading it from there
        // would use whichever placeholder happened to be bound first, which is whichever
        // filter the caller supplied: the screen would count overdue months against a date
        // chosen by the sort order.
        //
        // `count(distinct period_key)` rather than `count(*)`: the domain language is
        // "periodos vencidos", and two companies owing in one March is one late month. An
        // unknown light can no longer arrive here — the request refuses it — so there is no
        // `default` arm quietly meaning "red".
        $lateMonths = 'count(distinct obligations.period_key) '
            .'filter (where obligations.balance_cop > 0 and obligations.due_on < ?)';

        $date = $asOf->format('Y-m-d');

        match ($light) {
            TrafficLight::Green => $query->havingRaw("{$lateMonths} = 0", [$date]),
            TrafficLight::Yellow => $query->havingRaw("{$lateMonths} = 1", [$date]),
            TrafficLight::Orange => $query->havingRaw("{$lateMonths} = 2", [$date]),
            TrafficLight::Red => $query->havingRaw("{$lateMonths} >= 3", [$date]),
        };
    }

    /**
     * A calendar date, never an instant.
     *
     * §25. `ObligationTotals` already folded its reference to a day and the SQL comparisons
     * see dates, but the individual hydration compared a `due_on` at midnight against
     * `now()` at whatever time it was — so at 11:00 a debt due **today** came out overdue
     * while the same debt in the same response's summary did not. One debt, one answer.
     */
    private function agingReference(Carbon|string|null $asOf = null): Carbon
    {
        if ($asOf === null) {
            return now()->startOfDay();
        }

        if (is_string($asOf)) {
            return Carbon::parse($asOf)->startOfDay();
        }

        return $asOf->copy()->startOfDay();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sum(array $rows, string $key): int
    {
        return (int) array_sum(array_map(fn (array $row): int => (int) $row[$key], $rows));
    }
}

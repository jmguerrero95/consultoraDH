<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Models\ClientCompanyRate;
use App\Models\CutoffRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the configuration for a whole month in a fixed number of queries.
 *
 * ## The problem this replaces
 *
 * `RateResolver::resolve()` and `CutoffResolver::resolve()` each answered for one
 * client and company, and the candidate builder called them once per candidate. That is
 * one query for the rate and up to three for the cutoff — the client+company rule, the
 * company rule and the general rule — so a hundred relationships issued up to four
 * hundred queries to draw a preview. Period closing inherits the same cost, because
 * close recomputes the candidates to prove the month is complete, so an operator who
 * waited on a month that could not be closed was waiting on four hundred queries to be
 * told they could not close it.
 *
 * ## Four queries, whatever the portfolio size
 *
 * The hierarchy is preserved exactly; only the shape of the lookup changes. Each query
 * asks for the newest applicable rule **per key**, which PostgreSQL does with
 * `DISTINCT ON`, so the rows returned are bounded by the number of distinct keys rather
 * than by the length of the configuration history.
 *
 *   1. rates, one per `client + company`
 *   2. cutoff rules for `client + company`
 *   3. cutoff rules for `company`
 *   4. the general cutoff rule
 *
 * That is 3 + 1 regardless of whether there are ten candidates or ten thousand.
 *
 * ## Why `DISTINCT ON` rather than loading the table
 *
 * The obvious alternative is to load every applicable row and pick the newest per key
 * in PHP. That is bounded by the *history*, which grows every time an operator corrects
 * a rate, and a client with four years of monthly rates would pull four years of rows to
 * answer a question about one month. `DISTINCT ON` with the same
 * `(effective_month DESC, id DESC)` ordering the per-row resolver used keeps the
 * existing composite indexes useful and returns one row per key.
 *
 * The tie-break on `id` matters: two decisions for the same effective month are refused
 * by a unique index, but the ordering is written out anyway so the answer cannot depend
 * on which row the planner happens to return first.
 *
 * ## Nothing is invented
 *
 * A pair with no rate, or with no applicable cutoff rule anywhere in the hierarchy, is
 * reported as missing. The hierarchy is consulted in the same order and with the same
 * `effective_month <= billing month` bound as before, so a month that was blocked before
 * is still blocked, for the same reason.
 */
final class BatchedConfigResolver
{
    /**
     * @param  list<array{int, int}>  $pairs  `[client_id, company_id]`
     * @return array<string, ClientCompanyRate> keyed by `client:company`
     */
    public function rates(array $pairs, MonthValue $month): array
    {
        if ($pairs === []) {
            return [];
        }

        // A row-value `IN` list keeps this to one query and to exactly the rows that
        // matter. Filtering by `client_id IN (...)` alone would fetch every rate of
        // every one of those clients across every company, which is close to the whole
        // table; fetching all applicable rows and grouping in PHP would fetch the whole
        // effective history instead.
        [$pairPredicate, $pairBindings] = self::pairIn('client_id', 'company_id', $pairs);

        // §9. This fetched **every** applicable rate for the pairs and kept the first row
        // per key in PHP, so the query was bounded by the *pairs* but not by anything else:
        // one rate per key is one row, and a client whose rate had been corrected every
        // month for four years contributed forty-eight. The result was correct — the first
        // row per key was the decision in force — and the cost was not, and it grew with
        // history rather than with the month being billed.
        //
        // That is the exact alternative the class comment says it exists to avoid, and the
        // three cutoff queries below already use `DISTINCT ON` for it. Rates was the one
        // method that did not, which is why nothing noticed: the tests build one rate per
        // pair, so the history is a single row and both shapes return it.
        $query = ClientCompanyRate::query()->whereRaw($pairPredicate, $pairBindings);

        return $this->newestPerKey($query, ['client_id', 'company_id'], $month);
    }

    /**
     * Cutoff rules for a whole month: the three levels of the hierarchy, resolved.
     *
     * @param  list<array{int, int}>  $pairs
     * @return array{client: array<string, CutoffRule>, company: array<int, CutoffRule>, general: CutoffRule|null}
     */
    public function cutoffs(array $pairs, MonthValue $month): array
    {
        if ($pairs === []) {
            return ['client' => [], 'company' => [], 'general' => null];
        }

        $clientLevel = $this->newestPerKey(
            $this->withPairs(
                CutoffRule::query()->where('scope', CutoffScope::Client),
                $pairs,
            ),
            ['client_id', 'company_id'],
            $month,
        );

        $companyIds = array_values(array_unique(array_column($pairs, 1)));

        $companyLevel = $this->newestPerKey(
            CutoffRule::query()
                ->where('scope', CutoffScope::Company)
                ->whereIn('company_id', $companyIds),
            ['company_id'],
            $month,
        );

        $general = $this->newestPerKey(
            CutoffRule::query()->where('scope', CutoffScope::General),
            [],
            $month,
        );

        return [
            'client' => $clientLevel,
            'company' => $companyLevel,
            'general' => $general[''] ?? null,
        ];
    }

    /**
     * Restrict a query to a set of `client + company` pairs, in one bound predicate.
     *
     * Built as raw SQL rather than `whereIn(DB::raw(...), $pairs)` because Laravel's
     * `whereIn` refuses a list of arrays: it validates that every value is scalar, so the
     * composite form throws before it reaches the database. The row-value form is what
     * PostgreSQL wants for a composite key, and it lets the planner use the
     * `(client_id, company_id, effective_month)` index, which the equivalent
     * `client_id IN (...) AND company_id IN (...)` would not.
     *
     * @param  list<array{int, int}>  $pairs
     */
    private function withPairs(Builder $query, array $pairs): Builder
    {
        [$predicate, $bindings] = self::pairIn('client_id', 'company_id', $pairs);

        return $query->whereRaw($predicate, $bindings);
    }

    /**
     * `(a, b) IN ((1, 2), (3, 4))`, with the bindings flattened in the same order.
     *
     * @param  list<array{int, int}>  $pairs
     * @return array{0: string, 1: list<int>}
     */
    private static function pairIn(string $left, string $right, array $pairs): array
    {
        $groups = [];
        $bindings = [];

        foreach ($pairs as [$leftValue, $rightValue]) {
            $groups[] = '(?, ?)';
            $bindings[] = (int) $leftValue;
            $bindings[] = (int) $rightValue;
        }

        if ($groups === []) {
            // `IN ()` is a syntax error in PostgreSQL. The callers return early on an
            // empty candidate set; this keeps that guarantee local to the helper.
            return ['1 = 0', []];
        }

        return [
            sprintf('(%s, %s) IN (%s)', $left, $right, implode(', ', $groups)),
            $bindings,
        ];
    }

    /**
     * The rule that applies to one pair, following the hierarchy.
     *
     * Kept as a method so the batched result and a single lookup cannot disagree: this
     * is the same precedence the per-row resolver used, expressed once.
     *
     * @param  array{client: array<string, CutoffRule>, company: array<int, CutoffRule>, general: CutoffRule|null}  $cutoffs
     */
    public function ruleFor(int $clientId, int $companyId, array $cutoffs): ?CutoffRule
    {
        return $cutoffs['client'][$clientId.':'.$companyId]
            ?? $cutoffs['company'][$companyId]
            ?? $cutoffs['general'];
    }

    /**
     * One row per key, the newest applicable one.
     *
     * `DISTINCT ON` is what bounds the result to the keys rather than to the length of
     * the configuration history, and PostgreSQL has one strict requirement about it: the
     * `DISTINCT ON` expressions must be the **initial** `ORDER BY` expressions. The key
     * columns are therefore prepended here rather than ordered by date first, and the
     * date and id follow inside each key group — which is also the only ordering that
     * picks "the newest decision for *this* key".
     *
     * The general scope has no key columns: it is one rule per month for everybody, so it
     * needs no `DISTINCT ON` at all and is a plain `first()`.
     *
     * Shared by the three cutoff levels and by rates: the shape is the same question in all
     * four — "the newest decision in force for this key" — and it is asked once here rather
     * than four times, because the way to get it wrong is to write it out again without the
     * `DISTINCT ON`.
     *
     * @template TKey of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TKey>  $query
     * @param  list<string>  $keyColumns
     * @return array<string, TKey>
     */
    private function newestPerKey(Builder $query, array $keyColumns, MonthValue $month): array
    {
        $applicable = $query->where('effective_month', '<=', $month->startsOn());

        if ($keyColumns === []) {
            $row = $applicable
                ->orderByDesc('effective_month')
                ->orderByDesc('id')
                ->first();

            return $row === null ? [] : ['' => $row];
        }

        $rows = $applicable
            // Written as raw SQL rather than through `distinct()`: the query builder's
            // `distinct` property is typed as "a list of columns or a bool", and passing
            // several columns to it produces `select DISTINCT ... ,` instead of
            // `select DISTINCT ON (...)`, which is a different statement and a syntax
            // error here. The form wanted is a prefix on the select list, so it is
            // written as one.
            ->select(DB::raw('DISTINCT ON ('.implode(', ', $keyColumns).') *'));

        // One call per key column rather than `orderBy($keyColumns)`: the query builder
        // stores an array column verbatim and the grammar then tries to wrap an array as
        // a string.
        foreach ($keyColumns as $column) {
            $rows->orderBy($column);
        }

        $rows = $rows
            // The id tie-break keeps the answer stable if the unique index on the scope is
            // ever relaxed to allow two decisions for one effective month.
            ->orderByDesc('effective_month')
            ->orderByDesc('id')
            ->get();

        $resolved = [];

        foreach ($rows as $row) {
            $resolved[implode(':', array_map(
                static fn (string $column): string => (string) $row->{$column},
                $keyColumns,
            ))] = $row;
        }

        return $resolved;
    }
}

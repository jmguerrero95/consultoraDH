<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Models\CutoffRule;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns the configured rules into the two facts generation needs.
 *
 * A cutoff rule says "day 10, in the following month" for some scope. The resolver
 * turns that into a real date for a specific month, and records which rule it used
 * so the obligation can quote it.
 *
 * ## The hierarchy, most specific first
 *
 *   1. a `client` rule for this client **and this company**
 *   2. a `company` rule for this company
 *   3. the `general` rule
 *
 * The scopes are checked in that order and the first rule found is the answer, so
 * the more specific one wins by being consulted first rather than by any arithmetic
 * at the end.
 *
 * Note the client scope names a company as well as a client. A client working for
 * two companies at once has two separate employment relationships, and each may
 * count the contribution on its own date, so a client exception that did not say
 * which company it applied to would be an instruction nobody could carry out.
 *
 * ## Nothing is invented
 *
 * When no rule applies, this returns `missing`. It does not fall back to today's
 * date, to the last day of the month, to the first, or to another company's rule.
 * Every one of those would put a real due date on a real obligation and none of
 * them is a fact anybody told the system. Generation turns `missing` into a blocker
 * and writes nothing.
 */
final class CutoffResolver
{
    /**
     * The rule that applies to one client within one company for one month.
     */
    public function resolveRule(int $clientId, int $companyId, MonthValue $month): ?CutoffRule
    {
        foreach ($this->candidateQueries($clientId, $companyId, $month) as $query) {
            $rule = $query->first();

            if ($rule !== null) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * The resolved due date, and the rule that produced it.
     *
     * Returned together because the obligation stores both: the date is the
     * business fact and the rule is the evidence for it, and a row that has only the
     * date cannot answer "why the tenth?".
     */
    public function resolve(int $clientId, int $companyId, MonthValue $month): ResolvedCutoff
    {
        $rule = $this->resolveRule($clientId, $companyId, $month);

        if ($rule === null) {
            return ResolvedCutoff::missing($clientId, $companyId, $month);
        }

        return ResolvedCutoff::resolved(
            $rule->resolveFor($month),
            $rule,
            $clientId,
            $companyId,
            $month,
        );
    }

    /**
     * The scopes in precedence order.
     *
     * Each query is already ordered newest-first by `CutoffRule::scopeApplicableTo`,
     * so `first()` is the most recent applicable row and the row before it is
     * simply a later decision that has not started yet.
     *
     * @return list<Builder<CutoffRule>>
     */
    private function candidateQueries(int $clientId, int $companyId, MonthValue $month): array
    {
        // `applicableTo` is what makes this a *history* lookup rather than a plain
        // "the latest rule" lookup: it restricts to rules that had already started by
        // the month being generated and orders them newest first, so `first()` is the
        // most recent decision in force rather than the most recent row.
        return [
            CutoffRule::query()
                ->where('scope', CutoffScope::Client)
                ->where('client_id', $clientId)
                ->where('company_id', $companyId)
                ->applicableTo($month),

            CutoffRule::query()
                ->where('scope', CutoffScope::Company)
                ->where('company_id', $companyId)
                ->applicableTo($month),

            CutoffRule::query()
                ->where('scope', CutoffScope::General)
                ->applicableTo($month),
        ];
    }

    /**
     * The rule that would be used for a month, as a plain lookup for the interface.
     */
    public function effectiveRuleFor(int $clientId, int $companyId, MonthValue $month): ?CutoffRule
    {
        return $this->resolveRule($clientId, $companyId, $month);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Models\CutoffRule;
use App\Models\MonthlyObligation;
use Illuminate\Support\Carbon;

/**
 * A cutoff rule an obligation already quotes cannot be edited.
 *
 * The same reasoning as `RateInUse`, and for the same reason: an obligation stores
 * the rule's identifier as the evidence for its due date. Editing the rule afterwards
 * would change what the system says it billed for a month that has already been
 * generated. "When was this due?" would change its answer in November.
 *
 * Notes may always be edited. They are a description of the decision, not the
 * decision, and correcting a typo in one changes nobody's bill.
 */
final class CutoffRuleInUse extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function for(CutoffRule $rule): self
    {
        return new self(sprintf(
            'La fecha de corte con vigencia %s ya se usó para generar obligaciones, '
            .'y modificarla cambiaría la fecha de vencimiento que el sistema ya registró. '
            .'Cree una fecha de corte nueva con un mes de vigencia posterior.',
            $rule->month()->label(),
        ));
    }

    /**
     * Refuse only the fields that decide when something is due.
     *
     * @param  list<string>  $fields
     */
    public static function assertEditable(CutoffRule $rule, array $fields): void
    {
        $decides = array_intersect($fields, ['cutoff_day', 'month_offset', 'effective_month']);

        if ($decides === []) {
            return;
        }

        $used = MonthlyObligation::query()
            ->where('cutoff_rule_id', $rule->id)
            ->exists();

        if ($used) {
            throw self::for($rule);
        }
    }

    /**
     * The months a rule covers, for the message an operator reads.
     *
     * @return list<string>
     */
    public static function monthsAffectedBy(CutoffRule $rule): array
    {
        return MonthlyObligation::query()
            ->where('cutoff_rule_id', $rule->id)
            ->join('monthly_periods', 'monthly_periods.id', '=', 'monthly_obligations.period_id')
            ->orderBy('monthly_periods.period_month')
            ->pluck('monthly_periods.period_month')
            ->map(fn (mixed $month): string => MonthValue::fromFirstDay(Carbon::parse((string) $month))->label())
            ->unique()
            ->values()
            ->all();
    }
}

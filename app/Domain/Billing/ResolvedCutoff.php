<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Models\CutoffRule;
use Illuminate\Support\Carbon;

/**
 * What the cutoff rules said about one client, one company and one month.
 *
 * Either there is a date and a rule, or there is neither and `isMissing()` is true.
 * There is no third state, because "a date I worked out somehow" is not an answer
 * the system is allowed to give.
 */
final readonly class ResolvedCutoff
{
    private function __construct(
        public ?Carbon $dueOn,
        public ?CutoffRule $rule,
        public ?CutoffScope $source,
        public int $clientId,
        public int $companyId,
        public MonthValue $month,
    ) {}

    public static function resolved(
        Carbon $dueOn,
        CutoffRule $rule,
        int $clientId,
        int $companyId,
        MonthValue $month,
    ): self {
        return new self($dueOn, $rule, $rule->scope, $clientId, $companyId, $month);
    }

    public static function missing(int $clientId, int $companyId, MonthValue $month): self
    {
        return new self(null, null, null, $clientId, $companyId, $month);
    }

    public function isMissing(): bool
    {
        return $this->dueOn === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resolved' => ! $this->isMissing(),
            'due_on' => $this->dueOn?->format('Y-m-d'),
            'source' => $this->source?->value,
            'source_label' => $this->source?->label(),
            'cutoff_rule_id' => $this->rule?->id,
            'cutoff_day' => $this->rule?->cutoff_day,
            'month_offset' => $this->rule?->month_offset->value,
        ];
    }
}

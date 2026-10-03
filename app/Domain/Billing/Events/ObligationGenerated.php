<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\MonthlyObligation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One obligation was written into a month.
 *
 * One event per obligation rather than one per generation run, so the trail names
 * exactly which economic fact appeared. A run that created two hundred rows is two
 * hundred statements about two hundred obligations, and a reader can count them.
 *
 * The metadata carries the amount and the due date because those are what somebody
 * auditing a month will want, and neither requires reading another table. It
 * carries identifiers rather than names: a name is personal data, and the trail
 * already says which client by id.
 */
final readonly class ObligationGenerated implements AuditableEvent, SubjectAware
{
    public function __construct(
        public MonthlyObligation $obligation,
        public User $actor,
        public int $amountCop,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::ObligationGenerated;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->obligation;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            'period_id' => $this->obligation->period_id,
            'client_id' => $this->obligation->client_id,
            'company_id' => $this->obligation->company_id,
            'amount_cop' => $this->amountCop,
            'due_on' => $this->obligation->due_on?->format('Y-m-d'),
            'rate_id' => $this->obligation->rate_id,
            'cutoff_rule_id' => $this->obligation->cutoff_rule_id,
        ];
    }
}

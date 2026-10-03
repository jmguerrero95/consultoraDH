<?php

declare(strict_types=1);

namespace App\Domain\Periods\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\MonthlyPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final readonly class PeriodClosed implements AuditableEvent, SubjectAware
{
    public function __construct(
        public MonthlyPeriod $period,
        public User $actor,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::PeriodClosed;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->period;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            'period_id' => $this->period->id,
            'period_key' => $this->period->key(),
            'closed_at' => $this->period->closed_at?->toIso8601String(),
        ];
    }
}

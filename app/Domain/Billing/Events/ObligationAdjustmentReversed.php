<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\ObligationAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * An adjustment was undone by a reversal.
 *
 * Both entries are recorded: the reversal and what it undid. A reader looking at a
 * balance sees both, and can tell a correction that was itself corrected from one
 * that was simply written once.
 */
final readonly class ObligationAdjustmentReversed implements AuditableEvent, SubjectAware
{
    public function __construct(
        public ObligationAdjustment $reversal,
        public ObligationAdjustment $original,
        public User $actor,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::ObligationAdjustmentReversed;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->reversal->obligation;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        $obligation = $this->original->obligation;

        return [
            'reversal_id' => $this->reversal->id,
            'reverses_adjustment_id' => $this->original->id,
            'obligation_id' => $this->original->obligation_id,
            'client_id' => $obligation->client_id,
            'company_id' => $obligation->company_id,
            'period_id' => $obligation->period_id,
            'delta_cop' => $this->reversal->delta_cop,
            'reason' => $this->reversal->reason,
        ];
    }
}

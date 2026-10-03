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
 * An adjustment was posted against an obligation.
 *
 * The amount and the reason are the two things a reader needs. The reason is
 * recorded because a ledger without reasons is a list of numbers, and the reason is
 * what makes a correction explainable six months later.
 */
final readonly class ObligationAdjusted implements AuditableEvent, SubjectAware
{
    public function __construct(
        public ObligationAdjustment $adjustment,
        public User $actor,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::ObligationAdjusted;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->adjustment->obligation;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        $obligation = $this->adjustment->obligation;

        return [
            'adjustment_id' => $this->adjustment->id,
            'obligation_id' => $this->adjustment->obligation_id,

            // The identifiers of what was adjusted. An auditor reading the trail for
            // one client should not have to join through the obligations table to
            // find out which debt moved, and these are identifiers rather than names:
            // the name is not needed to answer "what was adjusted", and the trail is
            // read by people who should not be reading the client list.
            'client_id' => $obligation->client_id,
            'company_id' => $obligation->company_id,
            'period_id' => $obligation->period_id,

            'type' => $this->adjustment->type->value,
            'delta_cop' => $this->adjustment->delta_cop,
            'reason' => $this->adjustment->reason,
        ];
    }
}

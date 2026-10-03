<?php

declare(strict_types=1);

namespace App\Domain\Payments\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Money was applied to an obligation.
 *
 * The subject is the **obligation**, not the payment: what an auditor is asking is
 * "what did this person pay in March", and reading the obligation's trail answers
 * that in one place. The payment id is in the metadata so the other direction is
 * one filter away.
 */
final readonly class PaymentAllocated implements AuditableEvent, SubjectAware
{
    public function __construct(
        public PaymentAllocation $allocation,
        public User $actor,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::PaymentAllocated;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->allocation->obligation;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            'payment_id' => $this->allocation->payment_id,
            'allocation_id' => $this->allocation->id,
            'obligation_id' => $this->allocation->obligation_id,
            'amount_cop' => $this->allocation->amount_cop,
        ];
    }
}

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
 * An allocation was undone.
 *
 * The reason is in the metadata: an allocation that was put on the wrong obligation
 * is usually a mistake somebody understood at the time, and the reason is what tells
 * a later reader whether it was a mis-keyed amount or a wrong account.
 */
final readonly class PaymentAllocationReversed implements AuditableEvent, SubjectAware
{
    public function __construct(
        public PaymentAllocation $allocation,
        public User $actor,
        public string $reason,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::PaymentAllocationReversed;
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
            'allocation_id' => $this->allocation->id,
            'payment_id' => $this->allocation->payment_id,
            'obligation_id' => $this->allocation->obligation_id,
            'amount_cop' => $this->allocation->amount_cop,
            'reason' => $this->reason,
        ];
    }
}

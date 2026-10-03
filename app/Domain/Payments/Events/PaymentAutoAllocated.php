<?php

declare(strict_types=1);

namespace App\Domain\Payments\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment was applied to several obligations by the explicit oldest-first action.
 *
 * A separate action from `payment.allocated` because it records an *operator
 * decision to reconcile a backlog*, which is a different thing from applying a known
 * amount to a known debt, even though the individual allocations are also recorded.
 */
final readonly class PaymentAutoAllocated implements AuditableEvent, SubjectAware
{
    public function __construct(
        public Payment $payment,
        public User $actor,
        public int $appliedCount,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::PaymentAutoAllocated;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->payment;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            'payment_id' => $this->payment->id,
            'client_id' => $this->payment->client_id,
            'amount_cop' => $this->payment->amount_cop,
            'applied_count' => $this->appliedCount,
        ];
    }
}

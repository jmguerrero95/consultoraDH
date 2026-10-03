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
 * A payment was invalidated.
 *
 * The money stops counting and every balance that included it updates immediately,
 * because the balances are derived rather than stored. The allocations are kept: a
 * void says "this money did not stay", not "this was never allocated".
 */
final readonly class PaymentVoided implements AuditableEvent, SubjectAware
{
    public function __construct(
        public Payment $payment,
        public User $actor,
        public string $reason,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::PaymentVoided;
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
            'reason' => $this->reason,
        ];
    }
}

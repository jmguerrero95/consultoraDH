<?php

declare(strict_types=1);

namespace App\Domain\Payments\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final readonly class PaymentCreated implements AuditableEvent, SubjectAware
{
    public function __construct(
        public Payment $payment,
        public User $actor,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::PaymentCreated;
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
            'received_on' => $this->payment->received_on?->format('Y-m-d'),
            'method' => $this->payment->method->value,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\SupportSlaEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class SupportSlaEventEmitted implements AuditableEvent, SubjectAware
{
    public function __construct(
        public SupportSlaEvent $slaEvent
    ) {}

    public function action(): string
    {
        return 'support.sla.'.$this->slaEvent->level;
    }

    public function subject(): object
    {
        return $this->slaEvent;
    }

    public function auditAction(): AuditAction
    {
        return match ($this->slaEvent->level) {
            'breach' => AuditAction::SupportSlaBreached,
            'warning' => AuditAction::SupportSlaWarning,
            default => AuditAction::SupportSlaRecovered,
        };
    }

    public function auditActor(): ?Authenticatable
    {
        return null;
    }

    public function auditSubject(): ?Model
    {
        return $this->slaEvent;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
                'conversation_id' => $this->slaEvent->conversation_id,
                'metric' => $this->slaEvent->metric,
                'level' => $this->slaEvent->level,
                'due_at' => $this->slaEvent->due_at?->toIso8601String(),
            ];
    }
}

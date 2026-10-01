<?php

declare(strict_types=1);

namespace App\Domain\Affiliations\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\ClientAffiliation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final readonly class AffiliationCreated implements AuditableEvent, SubjectAware
{
    public function __construct(
        public ClientAffiliation $affiliation,
        public User $actor,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::AffiliationCreated;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->affiliation;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            'client_id' => $this->affiliation->client_id,
            'entity_id' => $this->affiliation->social_security_entity_id,
            'type' => $this->affiliation->type->value,
            'started_on' => $this->affiliation->started_on?->toDateString(),
            'arl_risk_class' => $this->affiliation->arl_risk_class,
        ];
    }
}

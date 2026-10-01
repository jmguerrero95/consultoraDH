<?php

declare(strict_types=1);

namespace App\Domain\Affiliations\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\ClientAffiliation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A client moved from one entity to another of the same type.
 *
 * Recorded as one event rather than a close plus a create, for the same reason
 * as the transfer: the change is one decision, taken in one transaction, and two
 * unrelated-looking entries would misrepresent it.
 *
 * History is preserved on both sides. This event is a description of the
 * transition; the closed row and the new row remain the authoritative record.
 */
final readonly class AffiliationChanged implements AuditableEvent, SubjectAware
{
    public function __construct(
        public ClientAffiliation $closed,
        public ClientAffiliation $opened,
        public User $actor,
        public string $effectiveDate,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::AffiliationChanged;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->closed;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            'client_id' => $this->closed->client_id,
            'type' => $this->closed->type->value,
            'from_entity_id' => $this->closed->social_security_entity_id,
            'to_entity_id' => $this->opened->social_security_entity_id,
            'effective_date' => $this->effectiveDate,
            'from_arl_risk_class' => $this->closed->arl_risk_class,
            'to_arl_risk_class' => $this->opened->arl_risk_class,
        ];
    }
}

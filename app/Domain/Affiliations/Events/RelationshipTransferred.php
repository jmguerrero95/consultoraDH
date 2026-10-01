<?php

declare(strict_types=1);

namespace App\Domain\Affiliations\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\ClientCompanyAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A client moved from one company to another.
 *
 * One event for the whole operation, on purpose: the transfer is a single
 * decision, and recording the closing of the old row and the opening of the new
 * one as two independent entries would make it impossible to see afterwards that
 * they were one decision made in one transaction.
 *
 * Both company identifiers are kept, because "which company did they leave, and
 * which did they join" is the whole question this event answers.
 */
final readonly class RelationshipTransferred implements AuditableEvent, SubjectAware
{
    public function __construct(
        public ClientCompanyAssignment $closed,
        public ClientCompanyAssignment $opened,
        public User $actor,
        public string $effectiveDate,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::RelationshipTransferred;
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
            'from_company_id' => $this->closed->company_id,
            'to_company_id' => $this->opened->company_id,
            'effective_date' => $this->effectiveDate,
            'to_job_title' => $this->opened->job_title,
        ];
    }
}

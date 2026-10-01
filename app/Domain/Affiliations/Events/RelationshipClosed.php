<?php

declare(strict_types=1);

namespace App\Domain\Affiliations\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\ClientCompanyAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final readonly class RelationshipClosed implements AuditableEvent, SubjectAware
{
    public function __construct(
        public ClientCompanyAssignment $assignment,
        public User $actor,
        public string $reason,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::RelationshipClosed;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->assignment;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            'client_id' => $this->assignment->client_id,
            'company_id' => $this->assignment->company_id,
            'ended_on' => $this->assignment->ended_on?->toDateString(),
            'reason' => $this->reason,
        ];
    }
}

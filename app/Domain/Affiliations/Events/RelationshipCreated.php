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
 * A client was linked to a company.
 *
 * The subject is the relationship row rather than the client, because the row is
 * the thing that happened. The client's own history is assembled from the rows
 * that point at it.
 */
final readonly class RelationshipCreated implements AuditableEvent, SubjectAware
{
    public function __construct(
        public ClientCompanyAssignment $assignment,
        public User $actor,
        public bool $parallel,
    ) {}

    public function auditAction(): AuditAction
    {
        return $this->parallel
            ? AuditAction::RelationshipParallelAuthorized
            : AuditAction::RelationshipCreated;
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
            'started_on' => $this->assignment->started_on?->toDateString(),
            'job_title' => $this->assignment->job_title,
        ];
    }
}

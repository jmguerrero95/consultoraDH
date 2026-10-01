<?php

declare(strict_types=1);

namespace App\Domain\Companies\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Field names only, for the same reason as the client equivalent: an append-only
 * table should not accumulate copies of editable values.
 */
final readonly class CompanyUpdated implements AuditableEvent, SubjectAware
{
    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public Company $company,
        public User $actor,
        public array $changed,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::CompanyUpdated;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->company;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            'changed_fields' => $this->changed,
        ];
    }
}

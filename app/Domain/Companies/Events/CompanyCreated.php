<?php

declare(strict_types=1);

namespace App\Domain\Companies\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final readonly class CompanyCreated implements AuditableEvent, SubjectAware
{
    public function __construct(
        public Company $company,
        public User $actor,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::CompanyCreated;
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
            'legal_name' => $this->company->legal_name,
            'tax_id' => $this->company->tax_id,
        ];
    }
}

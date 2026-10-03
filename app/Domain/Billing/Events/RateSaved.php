<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\ClientCompanyRate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A monthly rate was created or corrected.
 *
 * The amount is in the metadata because it is the whole content of the row, and an
 * audit of "what did we bill" starts by reading these.
 */
final readonly class RateSaved implements AuditableEvent, SubjectAware
{
    public function __construct(
        public ClientCompanyRate $rate,
        public User $actor,
        private AuditAction $action,
        /** @var list<string> */
        public array $changedFields = [],
    ) {}

    public function auditAction(): AuditAction
    {
        return $this->action;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->rate;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            ...$this->rate->toAuditMetadata(),
            'changed_fields' => $this->changedFields,
        ];
    }
}

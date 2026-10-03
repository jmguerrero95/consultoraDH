<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\CutoffRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A cutoff rule was created or changed.
 *
 * One class for both because the metadata is the same shape and the only difference
 * is the action name, which the caller states. Two classes would differ by four
 * lines.
 */
final readonly class CutoffRuleSaved implements AuditableEvent, SubjectAware
{
    public function __construct(
        public CutoffRule $rule,
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
        return $this->rule;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            ...$this->rule->toAuditMetadata(),
            // Which fields moved, by name only. The values are above; this answers
            // "what was touched", which is the question a configuration audit asks
            // first.
            'changed_fields' => $this->changedFields,
        ];
    }
}

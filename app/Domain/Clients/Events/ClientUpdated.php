<?php

declare(strict_types=1);

namespace App\Domain\Clients\Events;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\SubjectAware;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A client's editable details were changed.
 *
 * Only the names of the fields that actually changed are recorded, never their
 * new values: an investigator needs to know that a phone number was edited and
 * when, and does not need a copy of the number itself accumulating in an
 * append-only table that cannot be deleted from.
 */
final readonly class ClientUpdated implements AuditableEvent, SubjectAware
{
    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public Client $client,
        public User $actor,
        public array $changed,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::ClientUpdated;
    }

    public function auditActor(): User
    {
        return $this->actor;
    }

    public function auditSubject(): ?Model
    {
        return $this->client;
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

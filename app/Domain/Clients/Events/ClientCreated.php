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
 * A client master record was created.
 *
 * The document number is recorded because it is the identity an administrator
 * will later be asked about. Nothing else is: the audit trail is not a second
 * copy of the record, and duplicating the contact details there would create a
 * second place to look for personal data that nobody remembers to update.
 */
final readonly class ClientCreated implements AuditableEvent, SubjectAware
{
    public function __construct(
        public Client $client,
        public User $actor,
    ) {}

    public function auditAction(): AuditAction
    {
        return AuditAction::ClientCreated;
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
     * @return array<string, string>
     */
    public function auditMetadata(): array
    {
        // The names were here once and contradicted the note above: the record is
        // already reachable through the subject, so a second copy of someone's
        // name is personal data stored for no operational reason, and it is the
        // kind of copy that later drifts from the original.
        return [
            'document_type' => $this->client->document_type->value,
            'document_number' => $this->client->document_number,
        ];
    }
}

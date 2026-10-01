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
 * A client was activated or deactivated.
 *
 * One class for both directions, because the operation is the same decision
 * recorded twice and splitting it would only duplicate the metadata.
 */
final readonly class ClientStatusChanged implements AuditableEvent, SubjectAware
{
    public function __construct(
        public Client $client,
        public User $actor,
        public string $from,
        public string $to,
    ) {}

    public function auditAction(): AuditAction
    {
        return $this->to === 'active'
            ? AuditAction::ClientActivated
            : AuditAction::ClientDeactivated;
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
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}

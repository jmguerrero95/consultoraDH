<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for domain events that must be written to the audit trail.
 *
 * Implementing this interface is what makes an event auditable: the single
 * listener behind it derives the action, the actor and the metadata, so no
 * domain service has to know that the audit table exists.
 */
interface AuditableEvent
{
    /**
     * The action to record in the audit trail.
     */
    public function auditAction(): AuditAction;

    /**
     * The account the action is attributed to, or null when the action could
     * not be attributed (for example a failed login with an unknown address).
     */
    public function auditActor(): ?Authenticatable;

    /**
     * Structured, non-sensitive context for the audit entry.
     *
     * Implementations must never place passwords, password hashes, cookies,
     * CSRF tokens or session identifiers in this array. The recorder scrubs
     * the array again as a defence in depth.
     *
     * @return array<string, mixed>
     */
    public function auditMetadata(): array;
}

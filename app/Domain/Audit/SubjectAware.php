<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * An auditable event that concerns a business record.
 *
 * Separate from AuditableEvent so that the security events of A01, which have no
 * business subject, do not have to declare one they do not have. The recorder
 * checks for this interface, so implementing it is the only step needed to make
 * an event appear in the history of the record it changed.
 */
interface SubjectAware
{
    /**
     * The record the event is about, or null when it concerns none.
     */
    public function auditSubject(): ?Model;
}

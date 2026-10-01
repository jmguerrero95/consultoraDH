<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditRecorder;

/**
 * Persists every auditable domain event.
 *
 * One listener for the whole catalogue keeps the mapping in a single place and
 * means a new audited action only requires a new event class.
 */
final class RecordAuditEvent
{
    public function __construct(
        private readonly AuditRecorder $recorder,
    ) {}

    public function handle(AuditableEvent $event): void
    {
        $this->recorder->record(
            $event->auditAction(),
            $event->auditActor(),
            $event->auditMetadata(),
        );
    }
}

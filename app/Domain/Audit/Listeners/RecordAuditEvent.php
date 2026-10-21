<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AuditableEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\SubjectAware;

/**
 * Persists every auditable domain event.
 *
 * One listener for the whole catalogue keeps the mapping in a single place and
 * means a new audited action only requires a new event class.
 *
 * ## The guarantee depends on the class of the action
 *
 * A *critical* action is written through `recordRequired()`, so a failure to
 * persist it raises and the transaction that carries the business write rolls
 * back with it. An *auxiliary* action is written through `record()`, which
 * swallows the failure: an informative event must never fail a request.
 *
 * The listener is synchronous and is not deferred to after the commit, so the
 * audit INSERT runs on the same connection, inside the same transaction, as the
 * mutation it describes.
 */
final class RecordAuditEvent
{
    public function __construct(
        private readonly AuditRecorder $recorder,
    ) {}

    public function handle(AuditableEvent $event): void
    {
        $action = $event->auditAction();

        $metadata = $event->auditMetadata();

        // Null unless the event says which business record it concerns, so
        // the security events of A01 keep behaving exactly as before.
        $subject = $event instanceof SubjectAware ? $event->auditSubject() : null;

        if ($action->isCritical()) {
            $this->recorder->recordRequired($action, $event->auditActor(), $metadata, null, $subject);

            return;
        }

        $this->recorder->record($action, $event->auditActor(), $metadata, null, $subject);
    }
}

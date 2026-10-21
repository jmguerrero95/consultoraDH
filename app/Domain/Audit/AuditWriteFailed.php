<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use RuntimeException;
use Throwable;

/**
 * A *critical* audit entry could not be written.
 *
 * ## Why this exists
 *
 * The recorder used to swallow every write error and return `null`. That is
 * acceptable for an informative event — a missing `support.note.created` row
 * must not fail a note — but it is not acceptable for a mandatory one: a
 * payment that exists without a `payment.created` row is money that nobody can
 * explain later, and a trail that can silently lose an entry is not a trail.
 *
 * So the failure is raised, and because the audit write happens inside the same
 * transaction as the business write, raising it rolls the business write back.
 * The operator sees an error instead of an untraceable balance.
 */
final class AuditWriteFailed extends RuntimeException
{
    private function __construct(
        public readonly AuditAction $action,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function because(AuditAction $action, Throwable $previous): self
    {
        return new self(
            $action,
            sprintf(
                'The mandatory audit entry "%s" could not be written, so the change was reverted: %s',
                $action->value,
                $previous->getMessage(),
            ),
            $previous,
        );
    }
}

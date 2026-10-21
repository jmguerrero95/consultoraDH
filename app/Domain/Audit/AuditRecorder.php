<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes entries to the append-only audit trail.
 *
 * ## Two kinds of entry, two guarantees
 *
 * - `record()` is **tolerant**: an informative event whose write fails is
 *   reported to the log and dropped. Auditing must never be the reason a
 *   person's request fails.
 * - `recordRequired()` is **strict**: a mandatory event whose write fails raises
 *   `AuditWriteFailed`, so the enclosing transaction rolls back the business
 *   write with it. A payment, an obligation adjustment, a closed period, a
 *   changed rate, an affiliation, an applied import and an administrator are
 *   not allowed to exist without their trail entry.
 *
 * The class of an action is declared by `AuditAction::isCritical()`, not
 * decided by each call site, so the guarantee is the same for every caller.
 */
final class AuditRecorder
{
    public function __construct(
        private readonly MetadataScrubber $scrubber,
    ) {}

    /**
     * Write an informative entry. A failure is logged and swallowed.
     *
     * @param  array<string, mixed>  $metadata
     * @param  Model|null  $subject  the business record the action is about
     */
    public function record(
        AuditAction $action,
        ?Authenticatable $actor = null,
        array $metadata = [],
        ?Request $request = null,
        ?Model $subject = null,
    ): ?AuditEvent {
        $connection = AuditEvent::query()->getConnection();

        try {
            // A savepoint when the write happens inside somebody else's
            // transaction.
            //
            // PostgreSQL aborts the *whole* transaction when a statement
            // fails, so catching the error in PHP is not enough to keep the
            // enclosing work alive: the next statement would fail with
            // "current transaction is aborted" and the caller would see a
            // failure that has nothing to do with the audit entry it lost.
            // Rolling back to the savepoint undoes only the audit row.
            if ($connection->transactionLevel() > 0) {
                return $connection->transaction(
                    fn (): AuditEvent => $this->write($action, $actor, $metadata, $request, $subject)
                );
            }

            return $this->write($action, $actor, $metadata, $request, $subject);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Write a *mandatory* entry. A failure is raised, not swallowed.
     *
     * The caller is expected to be inside the transaction that carries the
     * business write: that is what turns the raised exception into a rollback
     * of the mutation instead of a mutation without a trail entry.
     *
     * @param  array<string, mixed>  $metadata
     * @param  Model|null  $subject  the business record the action is about
     *
     * @throws AuditWriteFailed
     */
    public function recordRequired(
        AuditAction $action,
        ?Authenticatable $actor = null,
        array $metadata = [],
        ?Request $request = null,
        ?Model $subject = null,
    ): AuditEvent {
        try {
            return $this->write($action, $actor, $metadata, $request, $subject);
        } catch (\Throwable $e) {
            report($e);

            throw AuditWriteFailed::because($action, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function write(
        AuditAction $action,
        ?Authenticatable $actor,
        array $metadata,
        ?Request $request,
        ?Model $subject,
    ): AuditEvent {
        $request ??= $this->currentRequest();

        return AuditEvent::query()->create([
            'user_id' => $actor?->getAuthIdentifier(),
            'subject_type' => $subject === null ? null : $subject->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'action' => $action->value,
            'ip_address' => $request?->ip(),
            'user_agent' => $this->truncateUserAgent($request?->userAgent()),
            'metadata' => $this->scrubber->scrub($metadata) ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Resolve the current request without exploding outside of an HTTP cycle
     * (queue workers, console commands, tests).
     */
    private function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    /**
     * User agents are attacker controlled, so the stored length is bounded.
     */
    private function truncateUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return mb_substr($userAgent, 0, 512);
    }
}

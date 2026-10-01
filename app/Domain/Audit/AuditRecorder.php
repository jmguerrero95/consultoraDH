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
 * Deliberately tolerant: auditing must never be the reason a user's request
 * fails, so write errors are reported to the log and swallowed. The call sites
 * therefore do not need to guard the call.
 */
final class AuditRecorder
{
    public function __construct(
        private readonly MetadataScrubber $scrubber,
    ) {}

    /**
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
        $request ??= $this->currentRequest();

        try {
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
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
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

<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * A provider call that did not succeed.
 *
 * ## Why the distinction matters
 *
 * `retryable` decides whether the resolver may try the fallback slot. Getting
 * this wrong in either direction is bad in a different way:
 *
 *   - A 401 or a malformed request marked retryable means the same bad call is
 *     repeated against the fallback, and a correct provider is bypassed because
 *     the primary's credential is wrong.
 *   - A timeout or a 429 marked non-retryable means the assistant gives up
 *     during a transient provider blip, when the fallback exists precisely for
 *     that moment.
 *
 * So transport, timeout and rate-limit are retryable; authentication, malformed
 * requests and anything the application itself rejected are not.
 */
class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public static function authentication(string $message): self
    {
        // Never retryable: another provider's key would not help, and repeating
        // a rejected credential is how a misconfiguration turns into a loop.
        return new self($message, retryable: false, status: 401);
    }

    public static function malformed(string $message): self
    {
        return new self($message, retryable: false, status: 400);
    }

    public static function rateLimited(string $message): self
    {
        return new self($message, retryable: true, status: 429);
    }

    public static function unavailable(string $message): self
    {
        // A transport failure, a timeout, or a 5xx: the same request may well
        // succeed elsewhere or a moment later.
        return new self($message, retryable: true, status: 503);
    }

    public static function notConfigured(): self
    {
        return new self('No AI provider is configured.', retryable: false, status: 503);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * A provider call that did not succeed.
 *
 * ## Why the distinction matters
 *
 * `retryable` decides whether the pool may try the next candidate, and `kind`
 * decides *how* the failure is remembered: a rejected credential disables an
 * endpoint, a missing model is set aside for hours, a timeout only cools the
 * model down for a moment. Getting either wrong is bad in a different way:
 *
 *   - A 401 marked retryable repeats the same bad call against every model of a
 *     broken credential and buries the real cause under a second failure.
 *   - A timeout marked non-retryable gives up during a transient blip when a
 *     second free model exists precisely for that moment.
 *   - A missing model (404) treated like a transport blip means a retired model
 *     is re-tried every minute forever instead of being parked.
 *
 * So: transport, timeout, rate limit, an empty answer and a model the provider
 * has never heard of are all retryable across candidates; a rejected credential
 * and a request this application itself refused are not. The `kind` repeated in
 * the health record is what makes "retryable" mean "try a *different* model".
 */
class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $status = null,
        public readonly string $kind = 'transport',
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $accountWide = false,
    ) {
        parent::__construct($message);
    }

    public static function authentication(string $message): self
    {
        // Never retryable: another model shares the same key, so retrying cannot
        // help, and the whole endpoint is disabled instead.
        return new self($message, retryable: false, status: 401, kind: 'auth');
    }

    /**
     * The provider refused the request as malformed.
     *
     * Retryable across candidates on purpose: free models differ in which
     * parameters they accept, so a request one model rejects another may serve.
     * The failure cools that model down rather than ending the turn.
     */
    public static function malformed(string $message): self
    {
        return new self($message, retryable: true, status: 400, kind: 'malformed');
    }

    /**
     * A 429.
     *
     * `$accountWide` distinguishes a limit that belongs to one model from one
     * that belongs to the whole account. The difference decides whether the next
     * model is tried (a model limit) or whether the endpoint is set aside as a
     * whole (a free-tier account limit), because switching models behind a
     * shared account limit is the same wall, not a recovery.
     */
    public static function rateLimited(string $message, ?int $retryAfterSeconds = null, bool $accountWide = false): self
    {
        return new self($message, retryable: true, status: 429, kind: 'rate_limited', retryAfterSeconds: $retryAfterSeconds, accountWide: $accountWide);
    }

    public static function unavailable(string $message): self
    {
        // A transport failure, a timeout, or a 5xx: the same request may well
        // succeed on another model or a moment later.
        return new self($message, retryable: true, status: 503, kind: 'transport');
    }

    /**
     * A 200 with neither prose nor a tool call.
     *
     * Retryable: another model may well answer. Kept as its own kind because a
     * model that keeps returning empty is not the same fault as one that is
     * unreachable, and the health record should say which it was.
     */
    public static function emptyAnswer(string $message = 'The AI provider returned an empty answer.'): self
    {
        return new self($message, retryable: true, status: 502, kind: 'empty');
    }

    /**
     * The provider does not know this model.
     *
     * Retryable across candidates — another model will work — but parked for a
     * long time, because until the catalogue or the configuration is corrected
     * this model will always 404.
     */
    public static function modelMissing(string $message): self
    {
        return new self($message, retryable: true, status: 404, kind: 'not_found');
    }

    public static function notConfigured(): self
    {
        return new self('No AI provider is configured.', retryable: false, status: 503, kind: 'not_configured');
    }
}

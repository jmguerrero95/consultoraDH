<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The MCP endpoint's own quota.
 *
 * ## Why the session throttle is not enough
 *
 * The REST API is protected by a session throttle: an authenticated person
 * with a browser session gets a limited number of requests. The MCP endpoint
 * is not a session. It authenticates with a long-lived bearer token, and it is
 * called by an agent that may loop. Inheriting nothing meant one token could
 * spend the whole provider budget — and the provider's own wall is a 429 that
 * arrives *after* the work, so the refusal is the wrong place to learn about it.
 *
 * ## Two limits, two purposes
 *
 * - The endpoint limit bounds the total number of calls a token may make.
 * - The tool limit bounds one tool per token, because the tools are not
 *   interchangeable: a read tool that scans the portfolio costs far more than
 *   one that reads a single record, so a single tool must not be able to spend
 *   the whole allowance.
 *
 * ## Counted per token, not per person
 *
 * The bucket is keyed by the MCP token, because the token is the credential
 * that was presented. Two tokens for the same person are two credentials, and
 * the audit trail of a quota is per credential.
 */
final class McpRateLimiter
{
    private const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly int $requestsPerMinute = 60,
        private readonly int $toolCallsPerMinute = 15,
    ) {}

    /**
     * Count the call, or refuse it.
     *
     * @return array{code: string, retry_after: int}|null null when the call may proceed
     */
    public function check(Request $request, string $method, ?string $tool = null): ?array
    {
        $identity = $this->identity($request);
        $isToolCall = $method === 'tools/call' && $tool !== null;

        if ($this->requestsPerMinute > 0) {
            $key = $identity.':requests';

            if (RateLimiter::tooManyAttempts($key, $this->requestsPerMinute)) {
                return $this->refusal($key, 'mcp_request_quota_exhausted');
            }
        }

        if ($isToolCall && $this->toolCallsPerMinute > 0) {
            $key = $identity.':tool:'.$tool;

            if (RateLimiter::tooManyAttempts($key, $this->toolCallsPerMinute)) {
                return $this->refusal($key, 'mcp_tool_quota_exhausted');
            }
        }

        if ($this->requestsPerMinute > 0) {
            RateLimiter::hit($identity.':requests', self::WINDOW_SECONDS);
        }

        if ($isToolCall && $this->toolCallsPerMinute > 0) {
            RateLimiter::hit($identity.':tool:'.$tool, self::WINDOW_SECONDS);
        }

        return null;
    }

    /**
     * @return array{code: string, retry_after: int}
     */
    private function refusal(string $key, string $code): array
    {
        return [
            'code' => $code,
            'retry_after' => max(1, RateLimiter::availableIn($key)),
        ];
    }

    /**
     * The bucket owner: the presented token, or the account behind it.
     */
    private function identity(Request $request): string
    {
        $tokenId = $request->attributes->get('mcp_token_id');
        $userId = $request->user()?->id;

        if (is_int($tokenId) || (is_string($tokenId) && $tokenId !== '')) {
            return 'mcp:token:'.$tokenId;
        }

        return 'mcp:user:'.($userId ?? 'anonymous');
    }
}

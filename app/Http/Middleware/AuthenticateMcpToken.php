<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\McpToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP Bearer token authentication middleware.
 *
 * Authenticates requests using MCP tokens from the `mcp_tokens` table.
 * Does not use Sanctum's personal access tokens.
 */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->header('Authorization');

        if (! $auth || ! preg_match('/^Bearer\s+(.+)$/', $auth, $matches)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => 'Token MCP faltante o inválido.',
                ],
            ], 401);
        }

        $rawToken = $matches[1];
        $tokenHash = hash('sha256', $rawToken);

        $mcpToken = McpToken::where('token_hash', $tokenHash)
            ->with('user')
            ->first();

        if ($mcpToken === null || ! $mcpToken->isValid()) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => 'Token MCP inválido o expirado.',
                ],
            ], 401);
        }

        // Record usage
        $mcpToken->recordUsage();

        // The presented token is the credential the quota is counted against:
        // two tokens for the same person are two credentials. Only the id is
        // exposed, never the token.
        $request->attributes->set('mcp_token_id', (int) $mcpToken->id);

        // Set the authenticated user
        $request->setUserResolver(fn () => $mcpToken->user);

        return $next($request);
    }
}

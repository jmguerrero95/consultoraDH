<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mcp;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * MCP Token management - restricted to users with appropriate permission.
 *
 * Issue/revoke MCP tokens for programmatic access.
 * Raw token returned ONLY ONCE at creation.
 */
final class McpTokenController extends Controller
{
    /**
     * List MCP tokens for the authenticated user (or all for admins).
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->user()->cannot('mcp.tokens.manage')) {
            // Users can only see their own tokens
            $tokens = McpToken::where('user_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->get();
        } else {
            // Admins can see all tokens
            $tokens = McpToken::with('user')
                ->orderByDesc('created_at')
                ->paginate($request->integer('per_page', 25));

            return response()->json($tokens);
        }

        return response()->json([
            'data' => $tokens->map(fn (McpToken $t): array => $this->presentToken($t))->all(),
        ]);
    }

    /**
     * Create a new MCP token for a user.
     *
     * Raw token is returned ONLY ONCE in the response.
     * Requires mcp.tokens.manage permission.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->user()->cannot('mcp.tokens.manage')) {
            return response()->json(['error' => 'No tiene permiso para crear tokens MCP.'], 403);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:100'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $targetUser = User::findOrFail($data['user_id']);

        $generated = McpToken::generate();

        $token = McpToken::create([
            'user_id' => $targetUser->id,
            'name' => $data['name'],
            'token_hash' => $generated['hash'],
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return response()->json([
            'token' => [
                'id' => $token->id,
                'name' => $token->name,
                'raw_token' => $generated['raw'], // ONLY returned once!
                'expires_at' => $token->expires_at?->toDateTimeString(),
                'created_at' => $token->created_at->toDateTimeString(),
            ],
            'warning' => 'Guarde este token ahora. No se volverá a mostrar.',
        ], 201);
    }

    /**
     * Show a specific token (hash never exposed).
     */
    public function show(Request $request, McpToken $token): JsonResponse
    {
        if ($request->user()->cannot('mcp.tokens.manage') && $token->user_id !== $request->user()->id) {
            return response()->json(['error' => 'No tiene permiso para ver este token.'], 403);
        }

        return response()->json([
            'token' => $this->presentToken($token),
        ]);
    }

    /**
     * Revoke an MCP token.
     *
     * Requires mcp.tokens.manage permission or ownership.
     */
    public function destroy(Request $request, McpToken $token): JsonResponse
    {
        if ($request->user()->cannot('mcp.tokens.manage') && $token->user_id !== $request->user()->id) {
            return response()->json(['error' => 'No tiene permiso para revocar este token.'], 403);
        }

        $token->revoke();

        return response()->json([
            'revoked' => true,
            'token_id' => $token->id,
            'revoked_at' => $token->revoked_at->toDateTimeString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentToken(McpToken $token): array
    {
        return [
            'id' => $token->id,
            'user_id' => $token->user_id,
            'user_name' => $token->user?->name,
            'name' => $token->name,
            'expires_at' => $token->expires_at?->toDateTimeString(),
            'last_used_at' => $token->last_used_at?->toDateTimeString(),
            'revoked_at' => $token->revoked_at?->toDateTimeString(),
            'is_valid' => $token->isValid(),
            'created_at' => $token->created_at->toDateTimeString(),
        ];
    }
}
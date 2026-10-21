<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mcp;

use App\Domain\Ai\AiToolRejected;
use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use App\Services\Mcp\McpRateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * MCP (Model Context Protocol) server - JSON-RPC 2.0 compatible.
 *
 * Implements standards-compatible JSON-RPC MCP endpoints:
 * - initialize
 * - tools/list
 * - tools/call
 *
 * Authentication: Bearer tokens with SHA-256 hashed storage.
 * - Raw token returned only once when issued via management endpoint
 * - Only secure hash stored in database
 * - Authenticate by hashing presented token and matching stored hash
 * - Verify: token exists, not expired, not revoked, linked user active, linked user authorized
 *
 * Uses the SAME AiToolRegistry as the REST API.
 * Write tools create normal AI action proposals (not direct mutations).
 *
 * ## What the caller is told, and what is kept in the log
 *
 * An MCP token is a machine credential. It may belong to an account with a
 * narrower scope than an administrator, and a message like "SQLSTATE[42P01]:
 * relation ... does not exist" or a provider exception tells a caller about
 * the inside of this deployment. So the protocol answers with a stable public
 * code and a sentence meant for the caller; the exception itself goes to the
 * MCP log channel, which is a file with its own permissions and is never
 * served.
 *
 * The endpoint also has a quota of its own (`McpRateLimiter`): it is not a
 * session, so the session throttle group does not cover it.
 */
final class McpController extends Controller
{
    private const METHODS = ['initialize', 'tools/list', 'tools/call'];

    public function __construct(
        private readonly AiToolRegistry $registry,
        private readonly McpRateLimiter $limiter,
    ) {}

    /**
     * JSON-RPC endpoint for MCP protocol.
     *
     * POST body: {
     *   "jsonrpc": "2.0",
     *   "id": 1,
     *   "method": "initialize|tools/list|tools/call",
     *   "params": {}
     * }
     *
     * Returns JSON-RPC response.
     */
    public function rpc(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return $this->jsonRpcError(null, -32600, 'Invalid or missing MCP token', 401);
        }

        $data = $request->json()->all();
        $id = $data['id'] ?? null;
        $method = is_string($data['method'] ?? null) ? $data['method'] : '';
        $params = is_array($data['params'] ?? null) ? $data['params'] : [];

        if (! in_array($method, self::METHODS, true)) {
            return $this->jsonRpcError($id, -32601, 'Method not found.', 200, [
                'code' => 'mcp_method_not_found',
            ]);
        }

        $tool = is_string($params['name'] ?? null) ? $params['name'] : null;

        if ($method === 'tools/call' && ($tool === null || ! $this->registry->has($tool))) {
            return $this->jsonRpcError($id, -32602, 'Tool not found.', 200, [
                'code' => 'mcp_tool_not_found',
            ]);
        }

        // The MCP endpoint is not a session, so it does not inherit the session
        // throttle. It carries a quota of its own, per token and per tool.
        $refusal = $this->limiter->check($request, $method, $tool);

        if ($refusal !== null) {
            return $this->jsonRpcError($id, -32000, 'Rate limit exceeded for the MCP endpoint.', 429, [
                'code' => $refusal['code'],
                'retry_after' => $refusal['retry_after'],
            ]);
        }

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($params, $user),
                'tools/list' => $this->handleToolsList($params, $user),
                'tools/call' => $this->handleToolsCall($params, $user),
            };

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ]);
        } catch (AiToolRejected $e) {
            // A refusal is a decision, not a failure: its message is written for
            // the person who asked and names no internals.
            return $this->jsonRpcError($id, -32602, $e->getMessage(), 200, [
                'code' => 'mcp_tool_rejected',
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->log('mcp_invalid_params', $e, ['method' => $method, 'user_id' => $user->id]);

            return $this->jsonRpcError($id, -32602, 'Invalid params.', 200, [
                'code' => 'mcp_invalid_params',
            ]);
        } catch (\Throwable $e) {
            $this->log('mcp_internal_error', $e, ['method' => $method, 'user_id' => $user->id]);

            return $this->jsonRpcError($id, -32603, 'Internal error.', 200, [
                'code' => 'mcp_internal_error',
            ]);
        }
    }

    /**
     * Health check endpoint (no auth required for basic check).
     */
    public function health(): JsonResponse
    {
        $toolCount = count($this->registry->all());

        return response()->json([
            'status' => 'ok',
            'registry_tool_count' => $toolCount,
            'tools_available' => array_keys($this->registry->all()),
            'mcp_version' => '2024-11-05',
            'protocol_version' => '2024-11-05',
        ]);
    }

    /**
     * MCP Initialize - negotiate protocol version and capabilities.
     */
    private function handleInitialize(array $params, User $user): array
    {
        $clientInfo = $params['clientInfo'] ?? ['name' => 'unknown', 'version' => 'unknown'];
        $protocolVersion = $params['protocolVersion'] ?? '2024-11-05';

        // We support the 2024-11-05 protocol version
        $supportedVersion = '2024-11-05';

        return [
            'protocolVersion' => $supportedVersion,
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => 'Consultora-DH',
                'version' => '1.0.0',
            ],
            'instructions' => 'MCP server for Consultora DH AI Assistant. Use tools/list to see available tools, tools/call to invoke them. Write tools create proposals requiring human confirmation.',
        ];
    }

    /**
     * MCP Tools List - return available tools as JSON-RPC tool definitions.
     */
    private function handleToolsList(array $params, User $user): array
    {
        $definitions = $this->registry->definitions();

        $tools = [];
        foreach ($definitions as $def) {
            $tools[] = [
                'name' => $def->name,
                'description' => $def->description,
                'inputSchema' => $def->parameters,
            ];
        }

        return [
            'tools' => $tools,
        ];
    }

    /**
     * MCP Tools Call - execute a tool call.
     *
     * Read tools execute immediately (if authorized).
     * Write tools create proposals (require human confirmation via REST API).
     */
    private function handleToolsCall(array $params, User $user): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (! is_string($name) || ! $this->registry->has($name)) {
            throw new \InvalidArgumentException('Tool not found.');
        }

        $tool = $this->registry->get($name);

        if ($tool->isWrite()) {
            // Same authority as the assistant turn: the registry validates and
            // normalizes, then applies the token owner's permissions, and only
            // what it returns is stored. A refusal creates no conversation and no
            // pending row, so there is nothing left to confirm later.
            $validated = $this->registry->proposeWrite($name, $arguments, $user);

            // Only now is a conversation worth creating, because only now is
            // there a proposal that will exist to hang from it.
            $conversation = AiConversation::query()->create([
                'user_id' => $user->id,
                'title' => 'Conversación MCP '.now()->format('Y-m-d H:i'),
            ]);

            $proposal = AiActionProposal::query()->create([
                'user_id' => $user->id,
                'ai_conversation_id' => $conversation->id,
                'tool' => $name,
                'arguments' => $validated,
                'status' => AiActionProposal::PENDING,
                'expires_at' => now()->addMinutes(config('ai.proposal_ttl_minutes', 15)),
            ]);

            // Write tools create proposals - do NOT execute directly
            // The proposal is returned to the caller for confirmation via REST API
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Esta herramienta ({$name}) produce una propuesta que requiere confirmación humana. ".
                            "La propuesta ha sido creada (ID: {$proposal->id}) y puede ser confirmada a través del endpoint REST /api/ai/action-proposals/{$proposal->id}/confirm. ".
                            'Argumentos validados: '.json_encode($validated, JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'isError' => false,
                '_meta' => [
                    'proposal' => true,
                    'proposal_id' => $proposal->id,
                    'tool' => $name,
                    'arguments' => $validated,
                ],
            ];
        }

        // Read tools execute immediately
        $validated = $tool->validate($arguments);

        try {
            $result = $this->registry->executeRead($name, $validated, $user);

            $text = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
                'isError' => false,
            ];
        } catch (AiToolRejected $e) {
            // A refusal is meant for the caller: it names the permission or the
            // argument, and nothing about the inside of this deployment.
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $e->getMessage(),
                    ],
                ],
                'isError' => true,
                '_meta' => ['code' => 'mcp_tool_rejected'],
            ];
        } catch (\Throwable $e) {
            // Anything else is an internal failure. The caller gets a stable
            // sentence; the trace stays in the MCP log.
            $this->log('mcp_tool_failed', $e, ['tool' => $name, 'user_id' => $user->id]);

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'La herramienta no pudo ejecutarse.',
                    ],
                ],
                'isError' => true,
                '_meta' => ['code' => 'mcp_internal_error'],
            ];
        }
    }

    /**
     * JSON-RPC error response.
     *
     * `data` carries the stable public code the caller may branch on. It never
     * carries the exception message: that is what the log is for.
     *
     * @param  array<string, mixed>  $data
     */
    private function jsonRpcError(mixed $id, int $code, string $message, int $httpStatus = 200, array $data = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ], $httpStatus);
    }

    /**
     * Keep the trace in the MCP channel, never in the reply.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(string $code, \Throwable $e, array $context = []): void
    {
        Log::channel((string) config('mcp.log_channel', 'mcp'))->error($code, array_merge($context, [
            'exception' => $e,
        ]));
    }
}
